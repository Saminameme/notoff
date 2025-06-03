<?php
namespace Lazyphp\Controller;

class AuthedApiController
{
    public function __construct()
    {
        // stoken 走最高优先级
        $stoken = t(v('stoken'));
        if (strlen($stoken) > 0) {
            login_by_stoken($stoken);
        } else {
            // 不认 cookie 带来的 php sessionid
            $token = t(v('token'));
            if (strlen($token) < 1) {
                return lianmi_throw('NOTLOGIN', '此接口需要登入才可调用');
            }
            session_id($token);
            session_start();
        }
        
        if (!isset($_SESSION['level']) || intval($_SESSION['level']) < 1 || intval($_SESSION['uid']) < 1) {
            return lianmi_throw('NOTLOGIN', '您的登入状态已过期，请重新登入');
        }
    }


    /**
     * 附件上传
     * 此接口只用于上传图片，并将 URL 返回，不关系具体的逻辑。可供 头像上传 和 栏目封面上传公用
     * @TODO 稍后需要添加数据统计，以避免图片被滥用
     * @ApiDescription(section="Global", description="图片上传")
     * @ApiLazyRoute(uri="/attach/upload",method="POST|GET")
     * * @ApiParams(name="name", type="string", nullable=false, description="name", check="check_not_empty", cnname="文件名称")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function attachUpload($name)
    {
        if (!isset($_FILES['attach'])) {
            return lianmi_throw('INPUT', '找不到上传的文件，[attach] 不存在');
        }

        if (intval($_FILES['attach']['error']) !== 0) {
            return lianmi_throw('INPUT', '文件上传失败');
        }
        
        $name = basename($name);
        if (mb_strlen($name, 'UTF-8') > 15) {
            $name = mb_substr($name, -15, null, 'UTF-8');
        }

        $path = 'u' . $_SESSION['uid'] . '/' . date("Y.m.d.") . uniqid() . $name ;

        if (!storage()->write($path, file_get_contents($_FILES['attach']['tmp_name']), ['visibility' => 'private'])) {
            return lianmi_throw('FILE', '保存文件失败');
        }

        return send_result(['name'=>$name , 'url' => path2url($path, 'attach') ]);
    }

    /**
     * 显示图片
     * @TODO 此接口不需要登入，以后会使用云存储或者x-send来替代
     * @ApiDescription(section="Global", description="显示图片接口")
     * @ApiLazyRoute(uri="/attach/@uid/@inner_path",method="GET|POST")
     * @ApiParams(name="uid", type="string", nullable=false, description="uid", check="check_not_empty", cnname="图片路径")
     * @ApiParams(name="inner_path", type="string", nullable=false, description="inner_path", check="check_not_empty", cnname="图片路径")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function showAttachment($uid, $inner_path)
    {
        $path = $uid .'/' . $inner_path;
        if (!$content = storage()->read($path)) {
            return lianmi_throw('FILE', '文件数据不存在');
        }
        $mime = storage()->getMimetype($path);

        header('Content-Type: ' . $mime);
        echo $content;

        return true;
    }

    /**
     * 图片上传
     * 此接口只用于上传图片，并将 URL 返回，不关系具体的逻辑。可供 头像上传 和 栏目封面上传公用
     * @TODO 稍后需要添加数据统计，以避免图片被滥用
     * @ApiDescription(section="Global", description="图片上传")
     * @ApiLazyRoute(uri="/image/upload",method="POST|GET")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function imageUpload()
    {
        if (!isset($_FILES['image']) || !isset($_FILES['image']['tmp_name']) || empty($_FILES['image']['tmp_name'])) {
            return lianmi_throw('INPUT', '找不到上传的文件，[image] 不存在');
        }
        if (intval($_FILES['image']['error']) !== 0) {
            return lianmi_throw('INPUT', '文件上传失败，错误代码: ' . $_FILES['image']['error']);
        }

        $tmp_name = $_FILES['image']['tmp_name'];

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $tmp_name);
        finfo_close($finfo);

        $allowed_mime_types = ['image/jpeg', 'image/png', 'image/gif'];
        if (!in_array($mime_type, $allowed_mime_types)) {
            return lianmi_throw('INPUT', '不支持的文件类型: ' . $mime_type . '. 只允许 JPEG, PNG, GIF.');
        }

        $extension = '';
        switch ($mime_type) {
            case 'image/jpeg': $extension = 'jpg'; break;
            case 'image/png': $extension = 'png'; break;
            case 'image/gif': $extension = 'gif'; break;
            default: return lianmi_throw('INPUT', '无法确定文件扩展名');
        }

        $path = 'u' . $_SESSION['uid'] . '/' . date("Y.m.d.") . uniqid() . '.' . $extension;

        try {
            $imgManager = new \Intervention\Image\ImageManagerStatic();
            $image = $imgManager->make($tmp_name);
            $image->orientate();
            $image_data = (string) $image->encode($extension, 90);

            if (!storage()->write($path, $image_data, ['visibility' => 'private', 'mimetype' => $mime_type])) {
                return lianmi_throw('FILE', '保存文件失败');
            }
        } catch (\Exception $e) {
            return lianmi_throw('FILE', '图像处理或保存失败: ' . $e->getMessage());
        }
        
        return send_result(['url' => path2url($path) ]);
    }

    /**
     * 图片上传
     * 此接口只用于上传图片，并将 URL 返回，不关系具体的逻辑。可供 头像上传 和 栏目封面上传公用
     * @TODO 稍后需要添加数据统计，以避免图片被滥用
     * @ApiDescription(section="Global", description="图片上传")
     * @ApiLazyRoute(uri="/image/upload_thumb",method="POST|GET")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function imageUploadToThumb()
    {
        if (!isset($_FILES['image'])) {
            return lianmi_throw('INPUT', '找不到上传的文件，[image] 不存在');
        }
        if (intval($_FILES['image']['error']) !== 0) {
            return lianmi_throw('INPUT', '文件上传失败');
        }
        
        $mime = strtolower($_FILES['image']['type']);
        if ($mime != 'image/png' && $mime != 'image/jpg' && $mime != 'image/jpeg') {
            return lianmi_throw('INPUT', '本接口只支持 png 和 jpg 格式的图片'.$mime);
        }

        $type = ($mime == 'image/png') ? 'png' : 'jpg';
        
        $prefix = 'u' . $_SESSION['uid'] . '/' . date("Y.m.d.") . uniqid() ;
        $path = $prefix. '.' . $type;
        $path_thumb = $prefix . '.thumb.'.$type;

        $img = new \Intervention\Image\ImageManager();
        $orignal_data = (string)$img->make($_FILES['image']['tmp_name'])->encode($type, 100);

        if (!storage()->write($path, $orignal_data, ['visibility' => 'private'])) {
            return lianmi_throw('FILE', '保存文件失败');
        }
        $orignal_url = path2url($path);

        $thumb_data = (string)$img->make($_FILES['image']['tmp_name'])->fit(200, 200, null, 'top')->encode($type, 100);
        if (!storage()->write($path_thumb, $thumb_data, ['visibility' => 'private'])) {
            return lianmi_throw('FILE', '保存文件失败');
        }
        
        $thumb_url = path2url($path_thumb);

        return send_result(compact('orignal_url', 'thumb_url', 'prexfix', 'type'));
    }

    /**
     * Audio Upload
     * @ApiDescription(section="Global", description="Audio file upload")
     * @ApiLazyRoute(uri="/audio/upload",method="POST")
     * @ApiParams(name="audio_file", type="file", nullable=false, description="Audio file to upload")
     * @ApiReturn(type="object", sample="{'code': 0, 'message': 'success', 'data': {'url': '...', 'name': 'filename.mp3', 'mimetype': 'audio/mpeg', 'size': 12345}}")
     */
    public function audioUpload()
    {
        if (!isset($_FILES['audio_file']) || !isset($_FILES['audio_file']['tmp_name']) || empty($_FILES['audio_file']['tmp_name'])) {
            return lianmi_throw('INPUT', '找不到上传的文件，[audio_file] 不存在');
        }
        if (intval($_FILES['audio_file']['error']) !== 0) {
            return lianmi_throw('INPUT', '文件上传失败，错误代码: ' . $_FILES['audio_file']['error']);
        }

        $tmp_name = $_FILES['audio_file']['tmp_name'];
        $original_name = basename($_FILES['audio_file']['name']);

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $tmp_name);
        finfo_close($finfo);

        $allowed_mime_types = ['audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/aac', 'audio/mp4'];
        if (!in_array($mime_type, $allowed_mime_types)) {
            return lianmi_throw('INPUT', '不支持的音频文件类型: ' . $mime_type);
        }

        $extension_map = ['audio/mpeg' => 'mp3', 'audio/ogg' => 'ogg', 'audio/wav' => 'wav', 'audio/aac' => 'aac', 'audio/mp4' => 'm4a'];
        $extension = isset($extension_map[$mime_type]) ? $extension_map[$mime_type] : pathinfo($original_name, PATHINFO_EXTENSION);
        if (empty($extension) && $mime_type === 'audio/mpeg') $extension = 'mp3';

        if (empty($extension)) {
            $original_extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
            if(in_array($original_extension, ['mp3', 'ogg', 'wav', 'aac', 'm4a'])){
                $extension = $original_extension;
            } else {
                 return lianmi_throw('INPUT', '无法确定音频文件扩展名，请确保文件名包含正确的扩展名.');
            }
        }

        $path = 'u' . $_SESSION['uid'] . '/' . date("Y.m.d.") . uniqid() . '.' . $extension;
        $file_size = $_FILES['audio_file']['size'];

        try {
            if (!storage()->write($path, file_get_contents($tmp_name), ['visibility' => 'private', 'mimetype' => $mime_type])) {
                return lianmi_throw('FILE', '保存音频文件失败');
            }
        } catch (\Exception $e) {
            return lianmi_throw('FILE', '音频文件保存异常: ' . $e->getMessage());
        }

        return send_result([
            'url' => path2url($path, 'attach'),
            'name' => $original_name,
            'mimetype' => $mime_type,
            'size' => $file_size,
            '_path_debug' => $path
        ]);
    }

    /**
     * 删除内容
     * @ApiDescription(section="Feed", description="删除内容")
     * @ApiLazyRoute(uri="/feed/remove/@id",method="POST|GET")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function feedRemove($id)
    {
        $id = intval($id); 
        $feed = get_line("SELECT * FROM `feed` WHERE `id` = :id AND `is_delete` != 1 LIMIT 1", [':id' => $id]);

        if (!$feed) {
            return lianmi_throw('INPUT', 'id对应的内容不存在或已被删除');
        }

        if (in_array($feed['status'], ['draft', 'scheduled']) && $feed['uid'] != lianmi_uid()) {
            return lianmi_throw('AUTH', 'You do not have permission to remove this content.');
        }
        
        if ($feed['is_forward'] == 1) {
            if ($feed['forward_uid'] != lianmi_uid()) {
                return lianmi_throw('AUTH', '只有栏主才能删除自己的通过的内容');
            }
        } else {
            if ($feed['uid'] != lianmi_uid()) {
                return lianmi_throw('AUTH', '只有作者才能删除自己的内容');
            }
        }
        
        $sql = "UPDATE `feed` SET `is_delete` = '1' WHERE `id` = :id LIMIT 1 ";
        run_sql($sql, [':id' => $id]);

        $feed['is_delete'] = 1;
        return send_result($feed);
    }

    /**
     * 设置栏目置顶
     * @ApiDescription(section="group", description="设置栏目置顶")
     * @ApiLazyRoute(uri="/group/top",method="POST|GET")
     * @ApiParams(name="feed_id", type="int", nullable=false, description="feed_id", cnname="内容id")
     * @ApiParams(name="group_id", type="int", nullable=false, description="group_id", cnname="栏目id")
     * @ApiParams(name="status", type="int", nullable=false, description="status", cnname="是否为置顶")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function groupTop($group_id, $feed_id, $status = 1)
    {
        $group_id = intval($group_id);
        $feed_id_for_query = intval($feed_id);
        $top_status_feed_id = ($status == 1) ? $feed_id_for_query : 0;

        if (!$group = table('group')->getAllById($group_id)->toLine()) {
            return lianmi_throw('INPUT', '错误的栏目ID，栏目不存在或已被删除');
        }
        
        if ($group['author_uid'] != lianmi_uid()) {
            return lianmi_throw('AUTH', '只有栏主才能修改栏目资料');
        }
        
        $feed = get_line("SELECT * FROM `feed` WHERE `id` = :feed_id AND `is_delete` != 1 LIMIT 1", [':feed_id' => $feed_id_for_query]);
        if (!$feed) {
            return lianmi_throw('INPUT', 'id对应的内容不存在或已被删除');
        }
        
        if ($feed['group_id'] != $group_id && $feed['forward_group_id'] != $group_id) {
            return lianmi_throw('AUTH', '只能置顶属于该栏目的内容');
        }
        
        $sql = "UPDATE `group` SET `top_feed_id` = :top_feed_id WHERE `id` = :group_id LIMIT 1 ";
        run_sql($sql, [':top_feed_id' => $top_status_feed_id, ':group_id' => $group_id]);

        $group['top_feed_id'] = $top_status_feed_id;

        return send_result($group);
    }

    /**
     * 更新内容
     * @ApiDescription(section="Feed", description="更新内容")
     * @TODO 此接口需要加入相同内容不能短时间重复更新的限制
     * @ApiParams(name="text", type="string", nullable=false, description="text", check="check_not_empty", cnname="内容内容")
     * @ApiParams(name="images", type="string", nullable=false, description="images", cnname="内容附图")
     * @ApiParams(name="attach", type="string", nullable=false, description="attach", cnname="内容附件")
     * @ApiParams(name="audio_file_json", type="string", nullable=true, description="Audio file JSON metadata", cnname="音频文件JSON")
     * @ApiParams(name="tags_json_string", type="string", nullable=true, description="JSON string array of tag names", cnname="标签JSON字符串")
     * @ApiParams(name="status", type="string", nullable=true, description="Feed status (e.g., 'published', 'draft', 'scheduled')", cnname="内容状态")
     * @ApiParams(name="scheduled_at_string", type="string", nullable=true, description="Scheduled publishing time (YYYY-MM-DD HH:MM:SS), or empty to clear schedule", cnname="定时发布时间")
    * @ApiParams(name="poll_question", type="string", nullable=true, description="Poll question text. If provided with options, a poll will be created if one doesn't exist.", cnname="投票问题")
    * @ApiParams(name="poll_options_json", type="string", nullable=true, description="JSON string array of poll options. Required if poll_question is set for creating a poll.", cnname="投票选项JSON")
     * @ApiParams(name="is_paid", type="int", nullable=false, description="is_paid", cnname="是否为付费内容")
     * @ApiLazyRoute(uri="/feed/update/@id",method="POST|GET")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function feedUpdate($id, $text, $images='', $attach = '', $audio_file_json = '', $tags_json_string = '', $status = null, $scheduled_at_string = null, $is_paid=0, $poll_question = null, $poll_options_json = null)
    {
        $id = intval($id);
        $is_paid = abs(intval($is_paid));
        
        $feed = get_line("SELECT * FROM `feed` WHERE `id` = :id AND `is_delete` != 1 LIMIT 1", [':id' => $id]);

        if (!$feed) {
            return lianmi_throw('INPUT', 'id对应的内容不存在或已被删除');
        }

        // Determine initial post_type based on existing feed or new content
        $post_type = $feed['post_type'];
        // $files_data_to_store = $feed['files']; // Default to existing files
        // attach is a general param, let's use it if audio_file_json is not taking precedence
        $files_data_to_store = $attach !== '' ? $attach : $feed['files'];


        if (isset($audio_file_json)) { // If audio_file_json is explicitly passed (even if empty string)
            if(!empty($audio_file_json)){
                $audio_data = @json_decode($audio_file_json, true);
                if ($audio_data && isset($audio_data['url'])) {
                    $files_data_to_store = $audio_file_json;
                    $post_type = 'audio';
                } else { // Invalid audio json, or clearing audio
                    $files_data_to_store = ''; // Clear previous audio if new json is invalid/empty
                    if ($post_type === 'audio' || $post_type === 'mixed') $post_type = 'text'; // Revert type if it was audio
                }
            } else { // Explicitly empty string means remove audio
                 $files_data_to_store = '';
                 if ($post_type === 'audio' || $post_type === 'mixed') $post_type = 'text';
            }
        }


        if (isset($images)) { // If images param is explicitly passed
            if(!empty($images)){
                // If post_type was audio and now images are added, it becomes mixed.
                // If it wasn't audio, it becomes image.
                $post_type = ($post_type === 'audio') ? 'mixed' : 'image';
            } else { // Explicitly empty string means remove images
                if($post_type === 'image') $post_type = 'text';
                // If it was 'mixed', and audio content ($files_data_to_store) is also empty, it becomes 'text'.
                // If audio remains, it becomes 'audio'.
                else if ($post_type === 'mixed') {
                    $post_type = ($files_data_to_store === '' || $files_data_to_store === null) ? 'text' : 'audio';
                }
            }
        } else { // images param not passed, retain original image relation unless audio overwrites
            // This means if $feed['images'] was not empty, and $post_type became 'audio' due to new audio,
            // it should now become 'mixed'.
            if (!empty($feed['images']) && $post_type === 'audio') {
                 $post_type = 'mixed';
            } else if (empty($feed['images']) && $post_type === 'mixed' && $files_data_to_store !== '' && $files_data_to_store !== null) {
                // If original was mixed, but images are now not set (relying on $feed['images'] being empty)
                // and audio is present, it should be 'audio'.
                // This case is complex; simpler to assume if $images is not passed, $feed['images'] is used later.
                // The $post_type calculation here is trying to be smart.
                // A simpler $post_type logic:
                // 1. Default to $feed['post_type'] or 'text'.
                // 2. If audio is set/changed, it becomes 'audio'.
                // 3. If images are set/changed (and not audio), it becomes 'image'.
                // 4. If both are present, 'mixed'.
                // 5. Poll overrides all. (Handled below)
            }
        }

        // Poll handling
        $existing_poll = get_line("SELECT `id` FROM `polls` WHERE `feed_id` = :feed_id LIMIT 1", [':feed_id' => $id]);

        if (!$existing_poll && !empty($poll_question) && !empty($poll_options_json)) {
            $poll_options = @json_decode($poll_options_json, true);
            if (is_array($poll_options) && count($poll_options) >= 2) {
                try {
                    run_sql("INSERT INTO `polls` (`feed_id`, `question`, `created_at`) VALUES (:feed_id, :question, :now)", [
                        ':feed_id' => $id,
                        ':question' => $poll_question,
                        ':now' => lianmi_now()
                    ]);
                    $poll_id = db()->lastId();
                    if ($poll_id) {
                        foreach ($poll_options as $option_text) {
                            if (!empty(trim($option_text))) {
                                run_sql("INSERT INTO `poll_options` (`poll_id`, `option_text`) VALUES (:poll_id, :option_text)", [
                                    ':poll_id' => $poll_id,
                                    ':option_text' => trim($option_text)
                                ]);
                            }
                        }
                        $post_type = 'poll'; // Set post_type to 'poll'
                    }
                } catch (\PDOException $e) {
                    // error_log("Poll creation error for feed $id: " . $e->getMessage());
                }
            }
        } elseif ($existing_poll && (!empty($poll_question) || !empty($poll_options_json))) {
            // Poll exists, and new poll data was sent. V1: no editing.
            // Optionally, log or notify that poll editing is not supported.
            // Ensure post_type remains 'poll' if it already was.
            if ($feed['post_type'] === 'poll') {
                $post_type = 'poll';
            }
        }

        // Final post_type check: if it's not a poll, and all content is empty, should be 'text'.
        $current_images = isset($images) ? $images : $feed['images'];
        if ($post_type !== 'poll' && empty($text) && empty($current_images) && ($files_data_to_store === '' || $files_data_to_store === null)) {
             $post_type = 'text';
        }

        if (!$feed) {
            return lianmi_throw('INPUT', 'id对应的内容不存在或已被删除');
        }
        
        if ($feed['uid'] != lianmi_uid()) {
            return lianmi_throw('AUTH', '只有作者才能修改自己的内容');
        }

        if (strlen($images) > 1) {
            if (!$image_list = @json_decode($images, 1)) { 
                $images = ''; 
            } else {
                foreach ($image_list as $image) {
                    if (!check_image_url($image['orignal_url']) || !check_image_url($image['thumb_url'])) {
                        return lianmi_throw('INPUT', '包含未被许可的图片链接，请重传图片后发布');
                    }
                }
            }
        }

        $update_fields = [
            "`text` = :text", "`images` = :images", "`files` = :files",
            "`is_paid` = :is_paid", "`post_type` = :post_type"
        ];
        $params = [
            ':text' => $text, ':images' => $images, ':files' => $files_data_to_store,
            ':is_paid' => $is_paid, ':post_type' => $post_type, ':id' => $id
        ];

        $new_status = ($status !== null && in_array($status, ['published', 'draft', 'scheduled'])) ? $status : $feed['status'];
        $new_scheduled_at = $feed['scheduled_at'];

        if ($scheduled_at_string !== null) {
            if (empty($scheduled_at_string)) {
                $new_scheduled_at = null;
                if ($new_status === 'scheduled') {
                    $new_status = ($feed['status'] === 'draft' && $status === null) ? 'draft' : 'published';
                }
            } else {
                try {
                    $scheduled_datetime = new \DateTime($scheduled_at_string);
                    $now_datetime = new \DateTime();
                    if ($scheduled_datetime > $now_datetime) {
                        $new_scheduled_at = $scheduled_datetime->format('Y-m-d H:i:s');
                        if ($new_status !== 'draft') {
                             $new_status = 'scheduled';
                        } else {
                            $new_scheduled_at = null;
                        }
                    } else {
                        $new_scheduled_at = null;
                        if ($new_status === 'scheduled') $new_status = 'published';
                    }
                } catch (\Exception $e) {
                    if ($new_status === 'scheduled') $new_status = $feed['status'];
                }
            }
        }

        if ($new_status === 'draft' || $new_status === 'published') {
            $new_scheduled_at = null;
        }

        if ($status === null && $feed['status'] === 'scheduled' && $new_scheduled_at === null) {
            $new_status = 'published';
        }

        if ($new_status !== $feed['status']) {
            $update_fields[] = "`status` = :status";
            $params[':status'] = $new_status;
        }
        if ($new_scheduled_at !== $feed['scheduled_at']) {
             $update_fields[] = "`scheduled_at` = :scheduled_at";
             $params[':scheduled_at'] = $new_scheduled_at;
        }

        if (!empty($update_fields)) {
            $sql = "UPDATE `feed` SET " . implode(', ', $update_fields) . " WHERE `id` = :id LIMIT 1 ";
            run_sql($sql, $params);
        }

        $feed['text'] = $text;
        $feed['images'] = $images; 
        $feed['files'] = $files_data_to_store;
        $feed['is_paid'] = $is_paid;
        $feed['post_type'] = $post_type;
        $feed['status'] = $new_status;
        $feed['scheduled_at'] = $new_scheduled_at;

        if ($tags_json_string !== null) {
            $tag_names = @json_decode($tags_json_string, true);
            if (is_array($tag_names)) {
                $this->processFeedTags($id, $tag_names);
            }
        }
        return send_result($feed);
    }

    /**
     * 发布内容
     * @ApiDescription(section="Feed", description="发布内容")
     * @TODO 此接口需要加入相同内容不能短时间重复发布的限制
     * @ApiParams(name="text", type="string", nullable=false, description="text", check="check_not_empty", cnname="内容内容")
     * @ApiParams(name="groups", type="string", nullable=false, description="groups", check="check_not_empty", cnname="目标栏目")
     * @ApiParams(name="images", type="string", nullable=false, description="images", cnname="内容附图")
    * @ApiParams(name="attach", type="string", nullable=false, description="attach", cnname="内容附件")
    * @ApiParams(name="audio_file_json", type="string", nullable=true, description="Audio file JSON metadata", cnname="音频文件JSON")
    * @ApiParams(name="tags_json_string", type="string", nullable=true, description="JSON string array of tag names", cnname="标签JSON字符串")
    * @ApiParams(name="status", type="string", nullable=true, description="Feed status (e.g., 'published', 'draft', 'scheduled')", cnname="内容状态")
    * @ApiParams(name="scheduled_at_string", type="string", nullable=true, description="Scheduled publishing time (YYYY-MM-DD HH:MM:SS)", cnname="定时发布时间")
    * @ApiParams(name="poll_question", type="string", nullable=true, description="Poll question text", cnname="投票问题")
    * @ApiParams(name="poll_options_json", type="string", nullable=true, description="JSON string array of poll options", cnname="投票选项JSON")
     * @ApiParams(name="is_paid", type="int", nullable=false, description="is_paid", cnname="是否为付费内容")
     * @ApiLazyRoute(uri="/feed/publish",method="POST|GET")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function feedPublish($text, $groups, $images='', $attach = '', $audio_file_json = '', $tags_json_string = '', $status = 'published', $scheduled_at_string = null, $is_paid=0, $poll_question = null, $poll_options_json = null)
    {
        $is_paid = abs(intval($is_paid));
        
        $current_status = in_array($status, ['published', 'draft', 'scheduled']) ? $status : 'published';
        $db_scheduled_at = null;

        if (!empty($scheduled_at_string)) {
            try {
                $scheduled_datetime = new \DateTime($scheduled_at_string);
                $now_datetime = new \DateTime();
                if ($scheduled_datetime > $now_datetime) {
                    // Only set to 'scheduled' if not explicitly set to 'draft'
                    if ($current_status !== 'draft') {
                        $current_status = 'scheduled';
                        $db_scheduled_at = $scheduled_datetime->format('Y-m-d H:i:s');
                    } else {
                         $db_scheduled_at = null; // If status is 'draft', scheduled_at must be null
                    }
                } else {
                    // It's a past date, so publish immediately if status was intended to be 'scheduled' or 'published'
                    if ($current_status === 'scheduled') $current_status = 'published';
                     $db_scheduled_at = null; // Clear any past schedule
                }
            } catch (\Exception $e) {
                // Invalid datetime string, treat as no schedule.
                // If status was 'scheduled', revert to 'published'.
                if ($current_status === 'scheduled') $current_status = 'published';
                $db_scheduled_at = null;
            }
        } else {
            // No scheduled_at_string provided. If status was 'scheduled' (e.g. from client default), revert to 'published'.
           if ($current_status === 'scheduled') $current_status = 'published';
           $db_scheduled_at = null; // Ensure it's null if no valid schedule string
        }

        // If the final status is draft, ensure scheduled_at is NULL
        if ($current_status === 'draft') {
           $db_scheduled_at = null;
        }

        $post_type = 'text';
        $files_data_to_store = $attach;

        if (!empty($audio_file_json)) {
            $audio_data = @json_decode($audio_file_json, true);
            if ($audio_data && isset($audio_data['url'])) {
                $files_data_to_store = $audio_file_json;
                $post_type = 'audio';
            }
        }

        if (!empty($images)) {
            $post_type = ($post_type === 'audio') ? 'mixed' : 'image';
        }

        // Poll data takes precedence for post_type if provided
        if (!empty($poll_question) && !empty($poll_options_json)) {
            $_poll_options_test = @json_decode($poll_options_json, true);
            if (is_array($_poll_options_test) && count($_poll_options_test) >= 2) {
                $post_type = 'poll';
            }
        }

        $group_ids = json_decode($groups, 1);
        
        if (!is_array($group_ids) || intval($group_ids[0]) < 1) {
            return lianmi_throw('INPUT', '目标栏目不能为空'.$groups);
        }

        $uid = lianmi_uid();
        $allowed_groups = get_data("SELECT * FROM `group_member` WHERE `uid` = :uid AND ( `is_author` = 1 OR `can_contribute` = 1 ) LIMIT ". intval(c('max_group_per_user')), [':uid' => $uid]);

        $allowed_gids = [];
        $author_gids = [];
        $member_gids = [];
        foreach ($allowed_groups as $item) {
            $allowed_gids[] = $item['group_id'];
            if ($item['is_author'] == 1) {
                $author_gids[] = $item['group_id'];
            } else {
                $member_gids[] = $item['group_id'];
            }
        }

        foreach ($group_ids as $key => $gid) {
            if (!in_array($gid, $allowed_gids)) {
                unset($group_ids[$key]);
            }
        }

        if (count($group_ids) < 1) {
            return lianmi_throw('INPUT', '您选择的栏目都没有发布或投稿权限，请重新选择');
        }

        if (strlen($images) > 1) {
            if (!$image_list = @json_decode($images, 1)) {
                $images = '';
            } else {
                foreach ($image_list as $image) {
                    if (!check_image_url($image['orignal_url']) || !check_image_url($image['thumb_url'])) {
                        return lianmi_throw('INPUT', '包含未被许可的图片链接，请重传图片后发布');
                    }
                }
            }
        }

        $now = lianmi_now();
        $uid = lianmi_uid();

        $sql_insert_feed = "INSERT INTO `feed` ( `text` , `group_id` , `images` , `files` , `uid` , `is_paid` , `timeline`, `post_type`, `status`, `scheduled_at` ) VALUES ( :text , '0' , :images , :files , :uid , :is_paid , :timeline, :post_type, :status, :scheduled_at )";
        $params_insert_feed = [
            ':text' => $text,
            ':images' => $images,
            ':files' => $files_data_to_store,
            ':uid' => $uid,
            ':is_paid' => $is_paid,
            ':timeline' => $now,
            ':post_type' => $post_type,
            ':status' => $current_status,
            ':scheduled_at' => $db_scheduled_at
        ];
        run_sql($sql_insert_feed, $params_insert_feed);
        $feed_id = db()->lastId();

        if ($feed_id) {
            if (!empty($tags_json_string)) {
                $tag_names = @json_decode($tags_json_string, true);
                if (is_array($tag_names) && !empty($tag_names)) {
                    $this->processFeedTags($feed_id, $tag_names);
                }
            }

            // Poll creation logic
            // $post_type is already determined before inserting the main feed
            if ($params_insert_feed[':post_type'] === 'poll' && !empty($poll_question) && !empty($poll_options_json)) {
                $poll_options = @json_decode($poll_options_json, true);
                if (is_array($poll_options) && count($poll_options) >= 2) {
                    try {
                        run_sql("INSERT INTO `polls` (`feed_id`, `question`, `created_at`) VALUES (:feed_id, :question, :now)", [
                            ':feed_id' => $feed_id,
                            ':question' => $poll_question,
                            ':now' => $now
                        ]);
                        $poll_id = db()->lastId();

                        if ($poll_id) {
                            foreach ($poll_options as $option_text) {
                                if (!empty(trim($option_text))) {
                                    run_sql("INSERT INTO `poll_options` (`poll_id`, `option_text`) VALUES (:poll_id, :option_text)", [
                                        ':poll_id' => $poll_id,
                                        ':option_text' => trim($option_text)
                                    ]);
                                }
                            }
                            // If post_type was not 'poll' initially due to other content, but poll was added, update it.
                            // This is already handled as $params_insert_feed[':post_type'] would be 'poll'.
                            // For clarity, we can ensure the $post_type variable used for forwarding is also 'poll'.
                            // $post_type = 'poll'; // Redundant if logic for $params_insert_feed[':post_type'] is correct
                        }
                    } catch (\PDOException $e) {
                        // Log error or handle
                        // error_log("Poll creation error for feed $feed_id: " . $e->getMessage());
                    }
                }
            }
        }

        if ($current_status == 'published') {
            if (is_array($author_gids) && count($author_gids) > 0) {
                foreach ($author_gids as $gid) {
                    if (!in_array($gid, $group_ids)) continue;
                    $gid = intval($gid);
                    // Note: $post_type for forwarded feed is correctly carried from original feed's $post_type (which is in $params_insert_feed[':post_type'])
                    $sql_forward = "INSERT INTO `feed` ( `text` , `group_id` , `images` , `files` , `uid` , `is_paid` , `timeline` , `is_forward` , `forward_feed_id` , `forward_uid` , `forward_text` , `forward_is_paid` , `forward_group_id` , `forward_timeline`, `post_type`, `status`, `scheduled_at`  ) VALUES ( :text , '0' , :images ,  :files , :original_uid , :is_paid , :original_timeline , '1' , :forward_feed_id , :forward_uid , '' , :is_paid , :forward_group_id , :forward_timeline, :post_type_fwd, 'published', NULL )";
                    $params_forward = [
                        ':text' => $text, ':images' => $images, ':files' => $files_data_to_store, ':original_uid' => $uid,
                        ':is_paid' => $is_paid, ':original_timeline' => $now,
                        ':forward_feed_id' => $feed_id, ':forward_uid' => $uid,
                        ':forward_group_id' => $gid, ':forward_timeline' => $now, ':post_type_fwd' => $params_insert_feed[':post_type'] // Use the determined post_type
                    ];
                    run_sql($sql_forward, $params_forward);
                    $forwarded_feed_id = db()->lastId();
                    if ($forwarded_feed_id && !empty($tags_json_string)) {
                        $tag_names = @json_decode($tags_json_string, true);
                        if (is_array($tag_names) && !empty($tag_names)) {
                            $this->processFeedTags($forwarded_feed_id, $tag_names);
                        }
                    }
                    run_sql("UPDATE `group` SET `feed_count` = ( SELECT COUNT(*) FROM `feed` WHERE `forward_group_id` = :gid AND `is_delete` != 1 ) WHERE `id`= :gid LIMIT 1", [':gid' => $gid]);
                }
            }
            if (is_array($member_gids) && count($member_gids) > 0) {
                foreach ($member_gids as $gid) {
                    if (!in_array($gid, $group_ids)) continue;
                    $gid = intval($gid);
                    $sql_contribute = "INSERT IGNORE INTO `feed_contribute` ( `uid` , `feed_id` , `group_id` , `status`, `timeline` ) VALUES ( :uid , :feed_id , :group_id , '0' , :timeline )";
                    run_sql($sql_contribute, [':uid' => $uid, ':feed_id' => $feed_id, ':group_id' => $gid, ':timeline' => $now]);
                    run_sql("UPDATE `group` SET `todo_count` = ( SELECT COUNT(*) FROM `feed_contribute` WHERE `group_id` = :gid AND `status` = 0 ) WHERE `id`= :gid LIMIT 1", [':gid' => $gid]);
                    $group = get_line("SELECT * FROM `group` WHERE `id`= :gid LIMIT 1", [':gid' => $gid]);
                    if ($group && $group['author_uid'] != $uid) {
                        system_notice($group['author_uid'], $uid, lianmi_username(), lianmi_nickname(), 'contribute to'. $group['name'], '/group/contribute/todo');
                    }
                }
            }
        }
        return send_result(compact('feed_id', 'text', 'groups', 'images', 'is_paid'));
    }

    /**
    * Vote for a poll option
    * @ApiDescription(section="Poll", description="Vote for a poll option")
    * @ApiLazyRoute(uri="/poll_option/@option_id/vote", method="POST")
    * @ApiParams(name="option_id", type="int", nullable=false, description="ID of the poll option to vote for", check="check_uint")
    * @ApiReturn(type="object", sample="{'code': 0, 'message': 'Vote cast successfully'}")
    */
   public function votePollOption($option_id)
   {
       $option_id = intval($option_id);
       $user_id = lianmi_uid();

       // Get poll_id from option_id
       $option = get_line("SELECT `id`, `poll_id` FROM `poll_options` WHERE `id` = :option_id LIMIT 1", [':option_id' => \$option_id]);
       if (!\$option) {
           return lianmi_throw('INPUT', 'Poll option not found.');
       }
       \$poll_id = \$option['poll_id'];

       // Check if the feed associated with the poll is published and accessible
       // Also ensures poll itself exists by joining.
       \$feed = get_line("SELECT f.id, f.status, f.uid FROM feed f JOIN polls p ON f.id = p.feed_id WHERE p.id = :poll_id AND f.is_delete = 0 LIMIT 1", [':poll_id' => \$poll_id]);
       if (!\$feed) {
           return lianmi_throw('INPUT', 'Associated feed not found or poll does not exist.');
       }

       // Allow voting if feed is published OR if the voter is the author of the feed (even if draft/scheduled).
       if (\$feed['status'] !== 'published' && \$feed['uid'] != \$user_id) {
           return lianmi_throw('AUTH', 'Cannot vote on unpublished polls unless you are the author.');
       }


       try {
           run_sql("INSERT INTO `poll_votes` (`poll_id`, `poll_option_id`, `user_id`, `created_at`) VALUES (:poll_id, :option_id, :user_id, :now)", [
               ':poll_id' => \$poll_id,
               ':option_id' => \$option_id,
               ':user_id' => \$user_id,
               ':now' => lianmi_now()
           ]);
           return send_result(['message' => 'Vote cast successfully']);
       } catch (\PDOException \$e) {
           if (\$e->getCode() == 23000) { // Unique constraint violation (poll_id, user_id)
               return lianmi_throw('ALREADY_VOTED', 'You have already voted on this poll.');
           }
           // error_log("Vote error: " . \$e->getMessage());
           return lianmi_throw('DATABASE', 'Could not cast vote.');
       }
   }

    /**
     * 获得我创建的栏目列表
     * @ApiDescription(section="Group", description="获得栏目列表")
     * @ApiLazyRoute(uri="/group/mine",method="GET|POST")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function getMineGroup()
    {
        $uid = lianmi_uid();
        return send_result(get_data("SELECT * FROM `group` WHERE `is_active` = 1 AND `author_uid` = :uid ORDER BY `promo_level` DESC , `member_count` DESC , `id` DESC LIMIT 100 ", [':uid' => $uid]));
    }

    /**
     * 获取栏目内容
     * @ApiDescription(section="Group", description="检查栏目购买数据")
     * @ApiLazyRoute(uri="/group/feed/@id",method="GET|POST")
     * @ApiParams(name="id", type="int", nullable=false, description="id", check="check_uint", cnname="栏目ID")
     * @ApiParams(name="since_id", type="int", nullable=false, description="since_id", cnname="游标ID")
     * @ApiParams(name="filter", type="int", nullable=false, description="filter", cnname="过滤选项")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function getGroupFeed($id, $since_id = 0, $filter = 'all')
    {
        $group_id = intval($id); // Use group_id for clarity
        $uid = lianmi_uid();
        $since_id = intval($since_id);

        $member_info = get_line("SELECT * FROM `group_member` WHERE `uid` = :uid AND `group_id` = :group_id LIMIT 1", [':uid' => $uid, ':group_id' => $group_id]);
        if (!$member_info) {
            return lianmi_throw('AUTH', '只有成员才能查看栏目内容');
        }

        $groupinfo = get_line("SELECT * FROM `group` WHERE `id` = :id LIMIT 1", [':id' => $group_id]);
        if (!$groupinfo) {
            return lianmi_throw('INPUT', '栏目不存在');
        }

        // 1. Fetch Top Feed
        $topfeed_data_final = false;
        if (isset($groupinfo['top_feed_id']) && intval($groupinfo['top_feed_id']) > 0) {
            $top_feed_id = intval($groupinfo['top_feed_id']);
            $topfeed_query_params = [':top_feed_id' => $top_feed_id];
            // Ensure the top feed is actually part of the current group to avoid misconfiguration issues
            $topfeed_sql = "SELECT *, uid as user, forward_group_id as group FROM feed WHERE id = :top_feed_id AND forward_group_id = :current_group_id AND is_delete = 0 AND status = 'published' LIMIT 1";
            $topfeed_query_params[':current_group_id'] = $group_id;

            $fetched_topfeed = get_line($topfeed_sql, $topfeed_query_params);

            if ($fetched_topfeed) {
                // Check visibility for paid top feed
                if ($fetched_topfeed['is_paid'] == 1 && $member_info['is_vip'] != 1 && $member_info['is_author'] != 1) {
                    // Non-VIP/author cannot see paid top feed, so effectively no top feed for this user
                    $topfeed_data_final = false;
                } else {
                    $topfeed_data_final = extend_field_oneline($fetched_topfeed, 'user', 'user');
                    $topfeed_data_final = extend_field_oneline($topfeed_data_final, 'group', 'group');
                }
            }
        }

        // 2. Fetch Pinned Feeds
        $pinned_feeds_sql = "SELECT *, uid as user, forward_group_id as group FROM feed WHERE forward_group_id = :group_id AND is_pinned_in_group = 1 AND is_delete = 0 AND status = 'published' ORDER BY timeline DESC LIMIT 5";
        $pinned_feeds_params = [':group_id' => $group_id];
        $pinned_feeds_data = get_data($pinned_feeds_sql, $pinned_feeds_params);

        if ($pinned_feeds_data) {
            // Filter out paid pinned feeds if user is not VIP/Author
            $pinned_feeds_data = array_filter($pinned_feeds_data, function($feed_item) use ($member_info) {
                if ($feed_item['is_paid'] == 1 && $member_info['is_vip'] != 1 && $member_info['is_author'] != 1) {
                    return false; // Exclude paid feed for non-VIP/author
                }
                return true;
            });
            $pinned_feeds_data = array_values($pinned_feeds_data); // Re-index

            $pinned_feeds_data = extend_field($pinned_feeds_data, 'user', 'user');
            $pinned_feeds_data = extend_field($pinned_feeds_data, 'group', 'group');

            // Filter out the main $topfeed_data_final from $pinned_feeds_data if it's duplicated
            if ($topfeed_data_final && isset($topfeed_data_final['id'])) {
                $pinned_feeds_data = array_filter($pinned_feeds_data, function($pf) use ($topfeed_data_final) {
                    return $pf['id'] != $topfeed_data_final['id'];
                });
                $pinned_feeds_data = array_values($pinned_feeds_data); // Re-index array
            }
        } else {
            $pinned_feeds_data = []; // Ensure it's an empty array if no pinned feeds
        }

        // 3. Prepare Excluded IDs for Regular Feeds
        $exclude_ids = [];
        if ($topfeed_data_final && isset($topfeed_data_final['id'])) {
            $exclude_ids[] = $topfeed_data_final['id'];
        }
        foreach ($pinned_feeds_data as $pf) {
            if (isset($pf['id'])) {
                $exclude_ids[] = $pf['id'];
            }
        }
        $exclude_ids = array_unique($exclude_ids);

        // 4. Construct exclude_sql_part for Regular Feeds Query
        $exclude_sql_part = "";
        if (!empty($exclude_ids)) {
            $safe_exclude_ids = array_map('intval', $exclude_ids); // Ensure all IDs are integers
            $exclude_sql_part = " AND id NOT IN (" . implode(',', $safe_exclude_ids) . ") ";
        }

        // 5. Fetch Regular Feeds
        $regular_feeds_filter_conditions = [];
        $regular_feeds_params = [':forward_group_id' => $group_id];

        if ($filter == 'paid') {
            $regular_feeds_filter_conditions[] = "`is_paid` = 1";
        }
        if ($filter == 'media') {
            $regular_feeds_filter_conditions[] = "`images` != ''";
        }
        
        // Apply visibility rules for regular feeds based on VIP status
        if ($member_info['is_vip'] != 1 && $member_info['is_author'] != 1) {
            $regular_feeds_filter_conditions[] = "`is_paid` != 1"; // Non-VIP/author cannot see paid regular feeds
        }
        
        if ($since_id > 0) {
            $regular_feeds_filter_conditions[] = "`id` < :since_id";
            $regular_feeds_params[':since_id'] = $since_id;
        }
        
        $regular_feeds_where_clause = "WHERE `is_delete` != 1 AND `forward_group_id` = :forward_group_id AND `status` = 'published' AND `is_pinned_in_group` = 0 ";
        if (!empty($regular_feeds_filter_conditions)) {
            $regular_feeds_where_clause .= " AND " . join(" AND ", $regular_feeds_filter_conditions);
        }
        $regular_feeds_where_clause .= $exclude_sql_part; // Add exclusion for top and pinned feeds

        $regular_feeds_sql = "SELECT *, `uid` as `user`, `forward_group_id` as `group` FROM `feed` {$regular_feeds_where_clause} ORDER BY `id` DESC LIMIT " . intval(c('feeds_per_page'));
        
        $regular_feeds_data = get_data($regular_feeds_sql, $regular_feeds_params);
        $regular_feeds_data = extend_field($regular_feeds_data, 'user', 'user');
        $regular_feeds_data = extend_field($regular_feeds_data, 'group', 'group');
        
        // 6. Calculate Pagination for Regular Feeds
        $maxid_for_regular = null; $minid_for_regular = null;
        if (is_array($regular_feeds_data) && count($regular_feeds_data) > 0) {
            $maxid_for_regular = $minid_for_regular = $regular_feeds_data[0]['id'];
            foreach ($regular_feeds_data as $item) {
                if ($item['id'] > $maxid_for_regular) $maxid_for_regular = $item['id'];
                if ($item['id'] < $minid_for_regular) $minid_for_regular = $item['id'];
            }
        }
            
        // 7. Return Structure
        return send_result([
            'topfeed'       => $topfeed_data_final ?: false,
            'pinned_feeds'  => $pinned_feeds_data,
            'feeds'         => $regular_feeds_data,
            'count'         => count($regular_feeds_data),
            'maxid'         => $maxid_for_regular,
            'minid'         => $minid_for_regular
        ]);
    }

    /**
     * 设置栏目成员黑名单
     * @ApiDescription(section="Group", description="设置栏目成员黑名单")
     * @ApiLazyRoute(uri="/group/blacklist",method="GET|POST")
     * @ApiParams(name="uid", type="int", nullable=false, description="uid", check="check_uint", cnname="用户ID")
     * @ApiParams(name="group_id", type="int", nullable=false, description="group_id", check="check_uint", cnname="栏目ID")
     * @ApiParams(name="status", type="int", nullable=false, description="status", check="check_uint", cnname="黑名单状态")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function setGroupBlackList($uid, $group_id, $status = 1)
    {
        $current_uid = lianmi_uid();
        $group_id = intval($group_id);
        $target_uid = intval($uid);
        $status = intval($status);

        $info = get_line("SELECT * FROM `group_member` WHERE `uid` = :current_uid AND `group_id` = :group_id LIMIT 1", [':current_uid' => $current_uid, ':group_id' => $group_id]);
        if (!$info || $info['is_author'] != 1) {
            return lianmi_throw('AUTH', '只有管理员才能设置栏目黑名单');
        }

        if ($status == 1) {
            if ($target_uid == $current_uid) {
                return lianmi_throw('INPUT', '不能将自己加入黑名单');
            }
            $sql = "INSERT IGNORE INTO `group_blacklist` ( `group_id` , `uid` , `timeline` ) VALUES ( :group_id , :target_uid , :timeline )";
            run_sql($sql, [':group_id' => $group_id, ':target_uid' => $target_uid, ':timeline' => lianmi_now()]);
            $this->quitGroup($group_id, $target_uid);
        } else {
            $sql = "DELETE FROM `group_blacklist` WHERE `group_id` = :group_id AND `uid` = :target_uid LIMIT 1";
            run_sql($sql, [':group_id' => $group_id, ':target_uid' => $target_uid]);
        }
        return send_result(['status'=>$status]);
    }

    /**
     * 设置栏目投稿黑名单
     * @ApiDescription(section="Group", description="设置栏目投稿黑名单")
     * @ApiLazyRoute(uri="/group/contribute_blacklist",method="GET|POST")
     * @ApiParams(name="uid", type="int", nullable=false, description="uid", check="check_uint", cnname="用户ID")
     * @ApiParams(name="group_id", type="int", nullable=false, description="group_id", check="check_uint", cnname="栏目ID")
     * @ApiParams(name="status", type="int", nullable=false, description="status", check="check_uint", cnname="黑名单状态")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function setGroupContributeBlackList($uid, $group_id, $status = 1)
    {
        if ($status != 1) { $status = 0; }
        
        $current_uid = lianmi_uid();
        $group_id = intval($group_id);
        $target_uid = intval($uid);
        
        $info = get_line("SELECT * FROM `group_member` WHERE `uid` = :current_uid AND `group_id` = :group_id LIMIT 1", [':current_uid' => $current_uid, ':group_id' => $group_id]);
        if (!$info || $info['is_author'] != 1) {
            return lianmi_throw('AUTH', '只有管理员才能设置栏目黑名单');
        }

        if ($target_uid == $current_uid) {
            return lianmi_throw('INPUT', '不能将自己加入黑名单');
        }

        $sql = "UPDATE `group_member` SET `can_contribute` = :can_contribute WHERE `uid` = :target_uid AND `group_id` = :group_id LIMIT 1";
        run_sql($sql, [':can_contribute' => $status, ':target_uid' => $target_uid, ':group_id' => $group_id]);

        return send_result(['status'=>$status]);
    }

    /**
     * 设置栏目评论黑名单
     * @ApiDescription(section="Group", description="设置栏目评论黑名单")
     * @ApiLazyRoute(uri="/group/comment_blacklist",method="GET|POST")
     * @ApiParams(name="uid", type="int", nullable=false, description="uid", check="check_uint", cnname="用户ID")
     * @ApiParams(name="group_id", type="int", nullable=false, description="group_id", check="check_uint", cnname="栏目ID")
     * @ApiParams(name="status", type="int", nullable=false, description="status", check="check_uint", cnname="黑名单状态")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function setGroupCommentBlackList($uid, $group_id, $status = 1)
    {
        if ($status != 1) { $status = 0; }
        
        $current_uid = lianmi_uid();
        $group_id = intval($group_id);
        $target_uid = intval($uid);

        $info = get_line("SELECT * FROM `group_member` WHERE `uid` = :current_uid AND `group_id` = :group_id LIMIT 1", [':current_uid' => $current_uid, ':group_id' => $group_id]);
        if (!$info || $info['is_author'] != 1) {
            return lianmi_throw('AUTH', '只有管理员才能设置栏目黑名单');
        }

        if ($target_uid == $current_uid) {
            return lianmi_throw('INPUT', '不能将自己加入黑名单');
        }

        $sql = "UPDATE `group_member` SET `can_comment` = :can_comment WHERE `uid` = :target_uid AND `group_id` = :group_id LIMIT 1";
        run_sql($sql, [':can_comment' => $status, ':target_uid' => $target_uid, ':group_id' => $group_id]);
        
        return send_result(['status'=>$status]);
    }

    /**
     * 获取栏目成员列表
     * @ApiDescription(section="Group", description="获取栏目成员列表")
     * @ApiLazyRoute(uri="/group/member/@id",method="GET|POST")
     * @ApiParams(name="id", type="int", nullable=false, description="id", check="check_uint", cnname="栏目ID")
     * @ApiParams(name="since_id", type="int", nullable=false, description="since_id", cnname="游标ID")
     * @ApiParams(name="filter", type="int", nullable=false, description="filter", cnname="过滤选项")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function getGroupMember($id, $since_id = 0, $filter = 'all')
    {
        $id = intval($id);
        $current_uid = lianmi_uid();
        $since_id = intval($since_id);

        if (!get_line("SELECT * FROM `group_member` WHERE `uid` = :current_uid AND `group_id` = :group_id LIMIT 1", [':current_uid' => $current_uid, ':group_id' => $id])) {
            return lianmi_throw('AUTH', '只有成员才能查看栏目成员');
        }

        $params = [':group_id' => $id];
        $filter_conditions = "";

        if ($filter == 'blacklist') {
            $base_sql_select = "SELECT * , `uid` as `user` FROM `group_blacklist`";
            $filter_conditions = " WHERE `group_id` = :group_id";
        } else {
            $base_sql_select = "SELECT * , `uid` as `user` FROM `group_member`";
            $filter_conditions = " WHERE `group_id` = :group_id";
            if ($filter == 'contribute') {
                $filter_conditions .= " AND `can_contribute` = 0 ";
            }
            if ($filter == 'comment') {
                $filter_conditions .= " AND `can_comment` = 0 ";
            }
        }
        
        if ($since_id > 0) {
            $filter_conditions .= " AND `id` < :since_id ";
            $params[':since_id'] = $since_id;
        }

        $sql = $base_sql_select . $filter_conditions ." ORDER BY `id` DESC LIMIT " . intval(c('users_per_page'));
        
        $group_black_list_uids = table('group_blacklist')->getUidByGroup_id($id)->toColumn('uid'); 
        
        $data = get_data($sql, $params);
        $data = extend_field($data, 'user', 'user');
        
        $maxid = null; $minid = null;
        if (is_array($data) && count($data) > 0) {
            $maxid = $minid = $data[0]['id'];
            foreach ($data as $key => $item) {
                if (isset($item['can_contribute'])) $data[$key]['user']['can_contribute'] = $item['can_contribute'];
                if (isset($item['can_comment'])) $data[$key]['user']['can_comment'] = $item['can_comment'];
                $data[$key]['user']['inblacklist'] = ($group_black_list_uids && in_array($item['uid'], $group_black_list_uids)) ? 1 : 0;
                if ($item['id'] > $maxid) $maxid = $item['id'];
                if ($item['id'] < $minid) $minid = $item['id'];
            }
        }
        return send_result(['members'=>$data , 'count'=>count($data) , 'maxid'=>$maxid , 'minid'=>$minid ]);
    }

    /**
     * 创建栏目
     * @TODO 当前版本不处理销售信息
     * @TODO 需要限制创建的栏目上限
     * @TODO 需要在栏目创建成功后，自动将作者加入到栏目里边
     * @ApiDescription(section="Group", description="创建栏目")
     * @ApiParams(name="name", type="string", nullable=false, description="name", check="check_not_empty", cnname="栏目名称")
     * @ApiParams(name="author_address", type="string", nullable=false, description="author_address", check="check_not_empty", cnname="栏目提现地址")
     * @ApiParams(name="price_wei", type="string", nullable=false, description="price_wei", check="check_uint", cnname="栏目年费价格")
     * @ApiParams(name="cover", type="string", nullable=false, description="cover", check="check_not_empty", cnname="栏目封面地址")
     * @ApiParams(name="seller_uid", type="string", nullable=false, description="seller_uid", cnname="销售商编号")
     * @ApiLazyRoute(uri="/group/create",method="POST|GET")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function groupCreate($name, $author_address, $price_wei, $cover, $seller_uid = 0)
    {
        $valid_price_wei = bigintval($price_wei);

        if (!check_image_url($cover)) {
            return lianmi_throw('INPUT', '包含未被许可的图片链接，请重传图片后发布');
        }
        
        if (mb_strlen($name, 'UTF8') < 3) {
            return lianmi_throw("INPUT", "栏目名字最短3个字");
        }
        
        if (get_var("SELECT COUNT(*) FROM `group` WHERE `name` = :name", [':name' => $name]) > 0) {
            return lianmi_throw("INPUT", "栏目名字已被占用，重新起一个吧");
        }
        
        $timeline = lianmi_now();
        $author_uid = lianmi_uid();
        $seller_uid_int = intval($seller_uid);

        $sql_insert_group = "INSERT INTO `group` ( `name` , `author_uid` , `author_address` , `price_wei` , `cover` , `seller_uid` , `timeline` , `is_active` , `is_paid` ) VALUES ( :name , :author_uid , :author_address , :price_wei , :cover , :seller_uid , :timeline , 1 , 1 )";
        $params_insert_group = [
            ':name' => t($name),
            ':author_uid' => $author_uid,
            ':author_address' => t($author_address),
            ':price_wei' => $valid_price_wei,
            ':cover' => t($cover),
            ':seller_uid' => $seller_uid_int,
            ':timeline' => $timeline
        ];
        run_sql($sql_insert_group, $params_insert_group);
        $group_id = db()->lastId();
        
        $sql_replace_member = "REPLACE INTO `group_member` ( `group_id` , `uid` , `is_author` , `is_vip` , `timeline` ) VALUES ( :group_id , :uid , '1' , '1' , :timeline )";
        run_sql($sql_replace_member, [':group_id' => $group_id, ':uid' => $author_uid, ':timeline' => $timeline]);

        run_sql("UPDATE `user` SET `group_count` = (SELECT COUNT(*) FROM `group_member` WHERE `uid` = :uid) WHERE `id` = :uid LIMIT 1", [':uid' => $author_uid]);
        run_sql("UPDATE `group` SET `member_count` = (SELECT COUNT(*) FROM `group_member` WHERE `group_id` = :group_id) WHERE `id` = :group_id LIMIT 1", [':group_id' => $group_id]);
        
        $group = [];
        $group['id'] = $group_id;
        
        foreach (['name','author_uid','author_address','price_wei','cover','seller_uid','timeline'] as $value) {
            $group[$value] = $$value;
        }

        return send_result($group);
    }

    /**
     * 加入栏目
     * @ApiDescription(section="Group", description="检查栏目购买数据")
     * @ApiLazyRoute(uri="/group/join/@id",method="GET|POST")
     * @ApiParams(name="id", type="int", nullable=false, description="id", check="check_uint", cnname="栏目ID")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function joinGroup($id)
    {
        $id = intval($id);
        $uid = lianmi_uid();

        if (intval(table('group')->getIs_activeById($id)->toVar()) != 1) {
            return lianmi_throw('AUTH', '该栏目尚未启用或已被暂停');
        }
        
        if (get_line("SELECT * FROM `group_blacklist` WHERE `group_id` = :group_id AND `uid` = :uid LIMIT 1", [':group_id' => $id, ':uid' => $uid])) {
            return lianmi_throw('AUTH', '你没有权限订阅该栏目');
        }
        
        $sql_insert_member = "INSERT IGNORE INTO `group_member` ( `group_id`, `uid` , `timeline` ) VALUES ( :group_id , :uid , :timeline )";
        run_sql($sql_insert_member, [':group_id' => $id, ':uid' => $uid, ':timeline' => lianmi_now()]);

        run_sql("UPDATE `user` SET `group_count` = (SELECT COUNT(*) FROM `group_member` WHERE `uid` = :uid) WHERE `id` = :uid LIMIT 1", [':uid' => $uid]);
        run_sql("UPDATE `group` SET `member_count` = (SELECT COUNT(*) FROM `group_member` WHERE `group_id` = :group_id) WHERE `id` = :group_id LIMIT 1", [':group_id' => $id]);

        return send_result("done");
    }

    /**
     * 退出栏目
     * @ApiDescription(section="Group", description="检查栏目购买数据")
     * @ApiLazyRoute(uri="/group/quit/@id",method="GET|POST")
     * @ApiParams(name="id", type="int", nullable=false, description="id", check="check_uint", cnname="栏目ID")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function quitGroup($id, $uid = null)
    {
        $id = intval($id);
        $target_uid = ($uid === null) ? lianmi_uid() : intval($uid);

        $info = get_line("SELECT * FROM `group_member` WHERE `uid` = :target_uid AND `group_id` = :group_id LIMIT 1", [':target_uid' => $target_uid, ':group_id' => $id]);
        if (!$info) {
            return lianmi_throw('INPUT', '尚未订阅该栏目');
        }

        if (intval($info['is_author']) > 0) {
            return lianmi_throw('INPUT', '栏主不能退订栏目');
        }
        
        run_sql("DELETE FROM `group_member` WHERE `group_id` = :group_id AND `uid` = :target_uid LIMIT 1", [':group_id' => $id, ':target_uid' => $target_uid]);
        run_sql("UPDATE `user` SET `group_count` = (SELECT COUNT(*) FROM `group_member` WHERE `uid` = :target_uid) WHERE `id` = :target_uid LIMIT 1", [':target_uid' => $target_uid]);
        run_sql("UPDATE `group` SET `member_count` = (SELECT COUNT(*) FROM `group_member` WHERE `group_id` = :group_id) WHERE `id` = :group_id LIMIT 1", [':group_id' => $id]);

        return send_result("done");
    }

    /**
     * 查询当前用户的基本信息
     * @ApiLazyRoute(uri="/user/self",method="GET|POST")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function userSelfInfo()
    {
        $uid = lianmi_uid();
        if (!$user = get_line("SELECT * FROM `user` WHERE `id` = :uid LIMIT 1", [':uid' => $uid])) {
            return lianmi_throw("INPUT", "用户不存在或已失效");
        }

        unset($user['password']) ;
        $user['uid'] = $user['id'];
        $user['token'] = session_id();
        $user = array_merge($user, get_group_info($user['id'])) ;
        return send_result($user);
    }

    /**
     * 检查当前用户VIP购买情况，并更新以此更新数据表中的数据
     * @ApiDescription(section="Group", description="检查栏目购买数据")
     * @ApiLazyRoute(uri="/group/vip/check/@id",method="GET|POST")
     * @ApiParams(name="id", type="int", nullable=false, description="id", check="check_uint", cnname="栏目ID")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function checkVipIsPaid($id)
    {
        $id = intval($id);
        $uid = lianmi_uid();

        $abi = json_decode(file_get_contents(AROOT . DS . 'contract' . DS . 'build' . DS . 'lianmi.abi'));
        $web3 = new \Web3\Providers\HttpProvider(new \Web3\RequestManagers\HttpRequestManager(c('web3_network'), 60));
        $contract = new \Web3\Contract($web3, $abi);
        
        $contract->at(c('contract_address'))->call('memberOf', $id, $uid, function ($error, $data) use ($id, $uid, $contract) {
            if ($error != null) {
                return lianmi_throw('CONTRACT', '合约调用失败：' . $error->getMessage());
            } else {
                $data = reset($data);
                $timestamp = intval($data->toString());
                $datetime = date("Y-m-d H:i:s", $timestamp);
                $is_vip = (time() <= $timestamp && $timestamp > 0) ? 1 : 0;

                $info = get_line("SELECT * FROM `group_member` WHERE `group_id` = :group_id AND `uid` = :uid LIMIT 1", [':group_id' => $id, ':uid' => $uid]);
                if (!$info) {
                    return lianmi_throw('INPUT', '你需要先订阅栏目才能购买VIP');
                }

                if ($info['is_vip'] != $is_vip || $info['vip_expire'] != $datetime) {
                    $sql = "UPDATE `group_member` SET `is_vip` = :is_vip , `vip_expire` = :vip_expire WHERE `group_id` = :group_id AND `uid` = :uid AND `id` = :member_id LIMIT 1";
                    run_sql($sql, [
                        ':is_vip' => $is_vip, 
                        ':vip_expire' => $datetime, 
                        ':group_id' => $id, 
                        ':uid' => $uid, 
                        ':member_id' => $info['id']
                    ]);
                }
                return send_result([ 'is_vip' => $is_vip , 'vip_expire' =>  $datetime ]);
            }
        });
    }

    /**
     * 购买小组VIP时，生成订单
     * @ApiDescription(section="Group", description="获取栏目投稿")
     * @ApiLazyRoute(uri="/group/preorder/@group_id",method="GET|POST")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    
    public function GroupPreorder($group_id)
    {
        $group_id = intval($group_id);
        $group = get_line("SELECT * FROM `group` WHERE `id` = :group_id LIMIT 1", [':group_id' => $group_id]);
        if (!$group) {
            return lianmi_throw("ARGS", "小组不存在");
        }

        $sql = "INSERT INTO `order` ( `group_id` , `author_address` , `group_price_wei` , `buyer_uid` , `created_at`  ) VALUES ( :group_id , :author_address , :group_price_wei , :buyer_uid , :created_at )";
        $params = [
            ':group_id' => $group_id,
            ':author_address' => $group['author_address'],
            ':group_price_wei' => $group['price_wei'],
            ':buyer_uid' => lianmi_uid(),
            ':created_at' => lianmi_now()
        ];
        run_sql($sql, $params);

        $order_id = db()->lastId();
        if ($order_id < 1) {
            return lianmi_throw("DATABASE", "预订单创建失败");
        }
        
        $url =  "https://wallet.fo/Pay?params=" .$group['author_address']  . ",FOUSDT,eosio,". intval($group['price_wei'])/100 ."," . u("order=".$order_id);
        $schema = "fowallet://".u($url);
        
        return send_result(["url"=>$url , "order_id"=> $order_id , "schema" => $schema ]);
    }

    /**
     * 检测小组VIP订单的支付情况
     * @ApiDescription(section="Group", description="获取栏目投稿")
     * @ApiLazyRoute(uri="/group/checkorder",method="GET|POST")
     * * @ApiParams(name="order_id", type="int", nullable=false, description="order_id", check="check_uint", cnname="订单号")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    
    public function GroupCheckorder($order_id)
    {
        $order_id = intval($order_id);
        $uid = lianmi_uid();

        $order = get_line("SELECT * FROM `order` WHERE `id` = :order_id LIMIT 1", [':order_id' => $order_id]);
        if (!$order) {
            return lianmi_throw("ARGS", "订单不存在");
        }

        if ($order['vip_active'] == 1) {
            return send_result(["done" => 1]);
        }
        
        if ($order['buyer_uid'] != $uid) {
            return lianmi_throw("ARGS", "你只能校验自己的订单");
        }

        if (!fo_check_user_tx($order['author_address'], $order_id, $order['group_price_wei'], 'FOUSDT@eosio')) {
            return lianmi_throw("AUTH", "尚未检测到转账结果，可能存在延迟，请确认到账后三到五分钟再查询");
        }

        $order = get_line("SELECT * FROM `order` WHERE `id` = :order_id LIMIT 1", [':order_id' => $order_id]);
        if (!$order) {
            return lianmi_throw("ARGS", "订单不存在");
        }
        if ($order['vip_active'] == 1) {
            return send_result(["done" => 1]);
        }

        $membership = get_line("SELECT * FROM `group_member` WHERE `uid` = :uid AND `group_id` = :group_id LIMIT 1", [':uid' => $uid, ':group_id' => $order['group_id']]);
        if (!$membership) {
            return lianmi_throw("AUTH", "先订阅栏目后才能购买VIP订户");
        }

        $expire_timestamp = (!isset($membership['vip_expire']) || $membership['vip_expire'] == "") 
            ? strtotime("+1 year") 
            : strtotime($membership['vip_expire']) + (60*60*24*365);
        $expire_datetime = date("Y-m-d H:i:s", $expire_timestamp);
            
        $sql_update_member = "UPDATE `group_member` SET `is_vip` = 1 , `vip_expire` = :expire_datetime WHERE `uid` = :uid AND `group_id` = :group_id LIMIT 1";
        run_sql($sql_update_member, [':expire_datetime' => $expire_datetime, ':uid' => $uid, ':group_id' => $order['group_id']]);

        $sql_update_order = "UPDATE `order` SET `vip_active` = 1 , `vip_start` = :vip_start WHERE `id` = :order_id LIMIT 1";
        run_sql($sql_update_order, [':vip_start' => lianmi_now(), ':order_id' => $order_id]);

        return send_result(["done" => 1]);
    }


    /**
     * 更新内容投稿状态
     * @ApiDescription(section="Group", description="获取栏目投稿")
     * @ApiLazyRoute(uri="/group/contribute/update",method="GET|POST")
     * @ApiParams(name="group_id", type="int", nullable=false, description="group_id",check="check_uint",, cnname="栏目ID")
     * @ApiParams(name="feed_id", type="int", nullable=false, description="feed_id",check="check_uint",, cnname="内容原始ID")
     * @ApiParams(name="status", type="int", nullable=false, description="status",check="check_uint",, cnname="投稿状态")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function updateContribute($group_id, $feed_id, $status)
    {
        $group_id = intval($group_id);
        $feed_id = intval($feed_id);
        $status = intval($status);
        $current_uid = lianmi_uid();

        if ($current_uid != get_var("SELECT `author_uid` FROM `group` WHERE `id` = :group_id LIMIT 1", [':group_id' => $group_id])) {
            return lianmi_throw('AUTH', '只有栏主才能审核投稿');
        }
        
        $contribute = get_line("SELECT * FROM `feed_contribute` WHERE `group_id` = :group_id AND `feed_id` = :feed_id LIMIT 1", [':group_id' => $group_id, ':feed_id' => $feed_id]);
        if (!$contribute) {
            return lianmi_throw('INPUT', '没有对应的投稿');
        }

        if ($contribute['status'] != $status) {
            if ($status == 1) { // Approve
                $feed = get_line("SELECT * FROM `feed` WHERE `id` = :feed_id LIMIT 1", [':feed_id' => $feed_id]);
                if (!$feed) {
                    return lianmi_throw('INPUT', '投稿对应的Feed不存在');
                }

                if ($contribute['forward_feed_id'] != 0) {
                    run_sql("UPDATE `feed` SET `is_delete` = 0 WHERE `forward_uid` = :current_uid AND `id` = :forward_feed_id LIMIT 1", [':current_uid' => $current_uid, ':forward_feed_id' => $contribute['forward_feed_id']]);
                } else {
                    $now = lianmi_now();
                    $sql_forward = "INSERT INTO `feed` ( `text` , `group_id` , `images`, `files` , `uid` , `is_paid` , `timeline` , `is_forward` , `forward_feed_id` , `forward_uid` , `forward_text` , `forward_is_paid` , `forward_group_id` , `forward_timeline`  ) VALUES ( :text , '0' , :images , :files , :original_uid , :is_paid , :original_timeline , '1' , :original_feed_id , :forward_uid , '' , :is_paid_forward , :forward_group_id , :forward_timeline )";
                    $params_forward = [
                        ':text' => $feed['text'], ':images' => $feed['images'], ':files' => $feed['files'], 
                        ':original_uid' => $feed['uid'], ':is_paid' => $feed['is_paid'], ':original_timeline' => $feed['timeline'],
                        ':original_feed_id' => $feed['id'], ':forward_uid' => $current_uid, 
                        ':is_paid_forward' => $feed['is_paid'], ':forward_group_id' => $group_id, ':forward_timeline' => $now
                    ];
                    run_sql($sql_forward, $params_forward);
                    $forward_feed_id = db()->lastId();
                    run_sql("UPDATE `feed_contribute` SET `status` = :status , `forward_feed_id` = :forward_feed_id WHERE `id` = :contribute_id LIMIT 1", [':status' => $status, ':forward_feed_id' => $forward_feed_id, ':contribute_id' => $contribute['id']]);
                }
            } else { // Reject or other status
                if ($contribute['status'] == 1 && $contribute['forward_feed_id'] != 0) { // Was previously approved
                    $the_feed = get_line("SELECT * FROM `feed` WHERE `id` = :forward_feed_id LIMIT 1", [':forward_feed_id' => $contribute['forward_feed_id']]);
                    if ($the_feed) {
                        if (strtotime($the_feed['timeline']) < strtotime("-1day") || $the_feed['comment_count'] > 0) {
                            run_sql("UPDATE `feed` SET `is_delete` = 1 WHERE `forward_uid` = :current_uid AND `id` = :forward_feed_id LIMIT 1", [':current_uid' => $current_uid, ':forward_feed_id' => $contribute['forward_feed_id']]);
                        } else {
                            run_sql("DELETE FROM `feed` WHERE `forward_uid` = :current_uid AND `id` = :forward_feed_id LIMIT 1", [':current_uid' => $current_uid, ':forward_feed_id' => $contribute['forward_feed_id']]);
                        }
                    }
                }
                run_sql("UPDATE `feed_contribute` SET `status` = :status WHERE `id` = :contribute_id LIMIT 1", [':status' => $status, ':contribute_id' => $contribute['id']]);
            }
            run_sql("UPDATE `group` SET `todo_count` = ( SELECT COUNT(*) FROM `feed_contribute` WHERE `group_id` = :group_id AND `status` = 0 ) WHERE `id`= :group_id LIMIT 1", [':group_id' => $group_id]);
        }
        return send_result('done');
    }

    /**
     * 获取全部栏目投稿
     * @ApiDescription(section="Group", description="获取栏目投稿")
     * @ApiLazyRoute(uri="/group/contribute",method="GET|POST")
     * @ApiParams(name="since_id", type="int", nullable=false, description="since_id", cnname="游标ID")
     * @ApiParams(name="filter", type="int", nullable=false, description="filter", cnname="过滤选项")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function getContribute($since_id = 0, $filter = 'all')
    {
        $filter_sql = '';
        if ($filter == 'todo') {
            $filter_sql = " AND `status` = '0' ";
        }
        if ($filter == 'allow') {
            $filter_sql = " AND `status` ='1' ";
        }
        if ($filter == 'deny') {
            $filter_sql = " AND `status` ='2' ";
        }
        
        $since_sql = $since_id == 0 ? "" : " AND `id` < '" . intval($since_id) . "' ";


        $uid = lianmi_uid();
        $params = [':uid' => $uid];
        $limit = intval(c('contribute_per_page'));

        $main_conditions = " 1 ";
        if ($filter == 'todo') $main_conditions .= " AND fc.`status` = 0 ";
        if ($filter == 'allow') $main_conditions .= " AND fc.`status` = 1 ";
        if ($filter == 'deny') $main_conditions .= " AND fc.`status` = 2 ";
        
        if ($since_id > 0) {
            $main_conditions .= " AND fc.`id` < :since_id ";
            $params[':since_id'] = intval($since_id);
        }

        $sql = "SELECT fc.`id`, fc.`feed_id`, fc.`feed_id` as `feed`, fc.`group_id`, fc.`group_id` as `group`, fc.`status` 
                FROM `feed_contribute` fc
                INNER JOIN `group` g ON fc.group_id = g.id
                WHERE {$main_conditions} AND g.`author_uid` = :uid
                ORDER BY fc.`id` DESC 
                LIMIT {$limit}";
        
        $data = get_data($sql, $params);
       
        if (is_array($data) && count($data) > 0) {
            $maxid = $minid = $data[0]['id'];
            foreach ($data as $item) {
                if ($item['id'] > $maxid) {
                    $maxid = $item['id'];
                }
                if ($item['id'] < $minid) {
                    $minid = $item['id'];
                }
            }

            
            $new_data = [];
            $to_group_ids = [];
            $group_status = [];

            foreach ($data as $key => $item) {
                $data[$key]['to_groups'] = [$item['group_id']];
                $group_status[$item['group_id']][$item['feed_id']] = $item['status'];
                
                $feed_id_exists = false;
                foreach ($new_data as $key2 => $preitem) {
                    if ($preitem['feed_id'] == $item['feed_id']) {
                        $feed_id_exists = true;
                        $new_data[$key2]['to_groups'] = array_merge($new_data[$key2]['to_groups'], $data[$key]['to_groups']);
                        $new_data[$key2]['to_groups'] = array_unique($new_data[$key2]['to_groups']);
                    }
                }
                if (!$feed_id_exists) {
                    $new_data[] = $data[$key];
                }
                $to_group_ids = array_merge($to_group_ids, $data[$key]['to_groups']);
                $to_group_ids = array_unique($to_group_ids);
            }


            if (is_array($to_group_ids) && count($to_group_ids) > 0) {
                $group_placeholders = implode(',', array_fill(0, count($to_group_ids), '?'));
                if ($group_infos = get_data("SELECT * FROM `group` WHERE `id` IN ( " . $group_placeholders . " )", $to_group_ids)) {
                    $group_infos_indexed = [];
                    foreach($group_infos as $gi) $group_infos_indexed[$gi['id']] = $gi;

                    foreach ($new_data as $key1 => $item) {
                        if (isset($item['to_groups']) && is_array($item['to_groups']) && count($item['to_groups']) > 0) {
                            foreach ($item['to_groups'] as $key2 => $gid) {
                                if (isset($group_infos_indexed[$gid])) {
                                    if (isset($group_status[$gid][$item['feed_id']])) {
                                        $group_infos_indexed[$gid]['status'] = $group_status[$gid][$item['feed_id']];
                                    }
                                    $new_data[$key1]['to_groups'][$key2] = $group_infos_indexed[$gid];
                                }
                            }
                        }
                    }
                }
            }
            
            $data = extend_field($new_data, 'feed', 'feed'); 
            
            $feed_uids = [];
            foreach ($data as $item) {
                if (isset($item['feed']['uid'])) {
                    $feed_uids[] = $item['feed']['uid'];
                }
                $feed_uids = array_unique($feed_uids);
            }

            if (count($feed_uids) > 0) {
                $user_placeholders = implode(',', array_fill(0, count($feed_uids), '?'));
                $user_sql = "SELECT ".c('user_normal_fields')." FROM `user` WHERE `id` IN (" . $user_placeholders .")";
                if ($userinfo = get_data($user_sql, $feed_uids)) {
                    $userinfo_indexed = [];
                    foreach($userinfo as $ui) $userinfo_indexed[$ui['id']] = $ui;

                    foreach ($data as $key => $item) {
                        if (isset($item['feed']['uid']) && isset($userinfo_indexed[$item['feed']['uid']])) {
                            $data[$key]['feed']['user'] = $userinfo_indexed[$item['feed']['uid']];
                        }
                        $data[$key]['feed']['to_groups'] = $item['to_groups'];
                        unset($data[$key]['to_groups']);
                        $data[$key]['feed']['forward_group_id'] = $item['group_id'];
                    }
                }
            }
        } else {
            $maxid = $minid = null;
        }
        return send_result(['feeds'=>$data , 'count'=>count($data) , 'maxid'=>$maxid , 'minid'=>$minid  ]);
    }

    /**
     * 删除评论
     * @ApiDescription(section="Feed", description="删除内容评论")
     * @ApiLazyRoute(uri="/comment/remove",method="GET|POST")
     * @ApiParams(name="id", type="int", nullable=false, description="id", check="check_uint", cnname="评论ID")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function removeFeedComment($id)
    {
        $id = intval($id);
        $comment = table('comment')->getAllById($id)->toLine();
        if (!$comment) {
            return lianmi_throw('INPUT', '评论不存在或已被删除');
        }

        $can_delete = false;
        if ($comment['uid'] == lianmi_uid()) {
            $can_delete = true;
        } else {
            $feed = table('feed')->getAllById($comment['feed_id'])->toLine();
            if ($feed) {
                $owner_uid = $feed['is_forward'] == 1 ? $feed['forward_uid'] : $feed['uid'];
                if ($owner_uid == lianmi_uid()) {
                    $can_delete = true;
                }
            }
        }

        if (!$can_delete) {
            return lianmi_throw('AUTH', '只有评论作者和内容主人才能删除该评论');
        }

        run_sql("UPDATE `comment` SET `is_delete` = '1' WHERE `id` = :id LIMIT 1", [':id' => $id]);
        
        run_sql("UPDATE `feed` SET `comment_count` = ( SELECT COUNT(*) FROM `comment` WHERE `feed_id` = :feed_id AND `is_delete` = 0 ) WHERE `id` = :feed_id LIMIT 1", [':feed_id' => $comment['feed_id']]);

        $comment['is_delete'] = 1;
        return send_result($comment);
    }


    /**
     * 对内容发起评论
     * @ApiDescription(section="Feed", description="对内容发起评论")
     * @ApiLazyRoute(uri="/feed/comment/@id",method="GET|POST")
     * @ApiParams(name="id", type="int", nullable=false, description="id", check="check_uint", cnname="内容ID")
     * @ApiParams(name="text", type="string", nullable=false, description="comment", check="check_not_empty", cnname="评论内容")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function saveFeedComment($id, $text)
    {
        $id = intval($id);
        $current_uid = lianmi_uid();

        $feed = get_line("SELECT *, `uid` as `user_obj` , `forward_uid` as `forward_user_obj` , `group_id` as `group_obj` FROM `feed` WHERE `id` = :id AND `is_delete` = 0 LIMIT 1", [':id' => $id]);
        if (!$feed) {
            return lianmi_throw('INPUT', 'ID对应的内容不存在或者你没有权限阅读');
        }

        if (in_array($feed['status'], ['draft', 'scheduled']) && $feed['uid'] != $current_uid) {
            return lianmi_throw('AUTH', 'You cannot comment on this content as it is a draft or scheduled.');
        }
        
        $group_id = $feed['is_forward'] == 1 ? $feed['forward_group_id'] : $feed['group_id'];
        $member_ship = [];
        if ($group_id > 0) {
             $member_ship = get_line("SELECT * FROM `group_member` WHERE `group_id` = :group_id AND `uid` = :uid LIMIT 1", [':group_id' => $group_id, ':uid' => $current_uid]);
        }

        $can_see = true;
        $can_comment = true;

        if ($feed['is_forward'] != 1) {
            if ($feed['uid'] == $current_uid) {
                $can_comment = true;
            } else {
                $is_blacklisted = get_line("SELECT * FROM `user_blacklist` WHERE `uid` = :feed_owner_uid AND `block_uid` = :current_uid LIMIT 1", [':feed_owner_uid' => $feed['uid'], ':current_uid' => $current_uid]);
                $can_comment = !$is_blacklisted;
            }
        } else {
             $can_comment = $member_ship && ($member_ship['can_comment'] == 1);
        }
        
        if ($feed['is_paid'] == 1) {
            $can_see = false;
            if ($feed['is_forward'] == 1) {
                if ($member_ship && ($member_ship['is_author'] == 1 || $member_ship['is_vip'] == 1)) {
                    $can_see = true;
                }
            } else {
                if ($current_uid == $feed['uid']) {
                    $can_see = true;
                }
            }
        }

        if (!$can_see || !$can_comment) {
            return lianmi_throw('AUTH', '没有权限查看或评论此内容，可使用有权限的账号登入后评论');
        }
        
        $now = lianmi_now();
        $sql_insert_comment = "INSERT INTO `comment` ( `feed_id` , `text` , `uid` , `timeline` ) VALUES ( :feed_id , :text , :uid , :timeline )";
        run_sql($sql_insert_comment, [':feed_id' => $id, ':text' => $text, ':uid' => $current_uid, ':timeline' => $now]);
        $cid = db()->lastId();

        run_sql("UPDATE `feed` SET `comment_count` = ( SELECT COUNT(*) FROM `comment` WHERE `feed_id` = :feed_id AND `is_delete` = 0  ) WHERE `id` = :feed_id LIMIT 1", [':feed_id' => $id]);

        $ouid = $feed['is_forward'] == 1 ? $feed['forward_uid'] : $feed['uid'];
        if ($ouid != $current_uid) {
            system_notice($ouid, $current_uid, lianmi_username(), lianmi_nickname(), 'comments on ['.$feed['id'].']', '/feed/'.$feed['id']);
        }

        if ($mention = lianmi_at($text)) {
            $mention = array_slice($mention, 0, intval(c('max_mention_per_comment')));
            if (!empty($mention)) {
                $placeholders = implode(',', array_fill(0, count($mention), '?'));
                $mention_uids = get_data("SELECT `id` FROM `user` WHERE `username` IN ( " . $placeholders . " )", $mention);
                if ($mention_uids) {
                    foreach ($mention_uids as $muid_row) {
                        $muid = $muid_row['id'];
                        if ($muid != $current_uid && $muid != $ouid) {
                            system_notice($muid, $current_uid, lianmi_username(), lianmi_nickname(), '在内容['.$feed['id'].']的评论中@了你', '/feed/'.$feed['id']);
                        }
                    }
                }
            }
        }
        return send_result([ 'feed_id'=>$id, 'text'=>$text , 'id' => $cid ]);
    }

    

    

    /**
     * 更新用户密码
     * @ApiDescription(section="User", description="更新用户密码")
     * @ApiLazyRoute(uri="/user/update_password",method="POST|GET")
     * @ApiParams(name="old_password", type="string", nullable=false, description="old_password", check="check_not_empty", cnname="原密码")
     * @ApiParams(name="new_password", type="string", nullable=false, description="new_password", check="check_not_empty", cnname="新密码")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function updateUserPassword($old_password, $new_password)
    {
        $uid = lianmi_uid();
        $user = table('user')->getAllById($uid)->toLine();
        if (!$user) {
            return lianmi_throw('INPUT', '当前用户不存在，什么鬼');
        }
        
        if (!password_verify($old_password, $user['password'])) {
            return lianmi_throw('INPUT', '错误的原密码');
        }
       
        if (strlen($new_password) < 6) {
            return lianmi_throw('INPUT', '密码长度不能短于6位');
        }
        
        $hash = password_hash($new_password, PASSWORD_DEFAULT);
        
        run_sql("UPDATE `user` SET `password` = :password WHERE `id` = :uid LIMIT 1", [':password' => $hash, ':uid' => $uid]);
        return send_result('done');
    }

    /**
     * 更新用户资料
     * @ApiDescription(section="User", description="更新用户资料")
     * @ApiLazyRoute(uri="/user/update_profile",method="POST|GET")
     * @ApiParams(name="nickname", type="string", nullable=false, description="nickname", check="check_not_empty", cnname="用户昵称")
     * @ApiParams(name="address", type="string", nullable=false, description="address",  cnname="钱包地址")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function updateUserInfo($nickname, $address = '')
    {
        $uid = lianmi_uid();
        $nickname = mb_substr(t($nickname), 0, 15, 'UTF-8');
        $address = t($address);
        
        if (in_array(strtolower($nickname), c('forbiden_nicknames'))) {
            return lianmi_throw('INPUT', '此用户昵称已被系统保留，请重新选择');
        }

        $sql = "UPDATE `user` SET `nickname` = :nickname , `address` = :address WHERE `id` = :uid LIMIT 1";
        run_sql($sql, [':nickname' => $nickname, ':address' => $address, ':uid' => $uid]);
        return send_result('done');
    }

    /**
     * 更新用户头像
     * @ApiDescription(section="User", description="更新用户头像")
     * @ApiLazyRoute(uri="/user/update_avatar",method="POST|GET")
     * @ApiParams(name="avatar", type="string", nullable=false, description="avatar", check="check_not_empty", cnname="头像地址")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function updateUserAvatar($avatar)
    {
        if (!check_image_url($avatar)) {
            return lianmi_throw('INPUT', '包含未被许可的图片链接，请重传图片后发布');
        }
        
        $uid = lianmi_uid();
        run_sql("UPDATE `user` SET `avatar` = :avatar  WHERE `id` = :uid LIMIT 1", [':avatar' => $avatar, ':uid' => $uid]);
        return send_result('done');
    }

    /**
     * 更新用户封面
     * @ApiDescription(section="User", description="更新用户封面")
     * @ApiLazyRoute(uri="/user/update_cover",method="POST|GET")
     * @ApiParams(name="cover", type="string", nullable=false, description="cover", check="check_not_empty", cnname="头像地址")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function updateUserCover($cover)
    {
        if (!check_image_url($cover)) {
            return lianmi_throw('INPUT', '包含未被许可的图片链接，请重传图片后发布');
        }
        
        $uid = lianmi_uid();
        run_sql("UPDATE `user` SET `cover` = :cover WHERE `id` = :uid LIMIT 1", [':cover' => $cover, ':uid' => $uid]);
        return send_result('done');
    }

    /**
     * 更新栏目资料
     * @ApiDescription(section="Group", description="更新栏目资料")
     * @ApiLazyRoute(uri="/group/update_settings",method="POST|GET")
     * @ApiParams(name="id", type="int", nullable=false, description="id", check="check_uint", cnname="栏目ID")
     * @ApiParams(name="name", type="string", nullable=false, description="name", check="check_not_empty", cnname="栏目名称")
     * @ApiParams(name="cover", type="string", nullable=false, description="cover", check="check_not_empty", cnname="封面地址")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function updateGroupSettings($id, $name, $cover)
    {
        $id = intval($id);
        $current_uid = lianmi_uid();

        if (!check_image_url($cover)) {
            return lianmi_throw('INPUT', '包含未被许可的图片链接，请重传图片后发布');
        }

        $group = table('group')->getAllById($id)->toLine();
        if (!$group) {
            return lianmi_throw('INPUT', '错误的栏目ID，栏目不存在或已被删除');
        }
        
        if ($group['author_uid'] != $current_uid) {
            return lianmi_throw('AUTH', '只有栏主才能修改栏目资料');
        }

        $name = t($name);
        if ($name != $group['name']) {
            if (mb_strlen($name, 'UTF8') < 3) {
                return lianmi_throw("INPUT", "栏目名字最短3个字");
            }
            if (get_var("SELECT COUNT(*) FROM `group` WHERE `name` = :name AND `id` != :id", [':name' => $name, ':id' => $id]) > 0) {
                return lianmi_throw("INPUT", "栏目名字已被占用，重新起一个吧");
            }
        }
        
        $sql = "UPDATE `group` SET `name` = :name , `cover` = :cover WHERE `id` = :id AND `author_uid` = :author_uid LIMIT 1";
        run_sql($sql, [':name' => $name, ':cover' => $cover, ':id' => $id, ':author_uid' => $current_uid]);

        $group['name'] = $name;
        $group['cover'] = $cover;
        return send_result($group);
    }

    /**
     * 判断某用户是否在黑名单
     * @ApiDescription(section="User", description="判断某用户是否在黑名单")
     * @ApiLazyRoute(uri="/user/inblacklist",method="POST|GET")
     * @ApiParams(name="uid", type="int", nullable=false, description="uid", cnname="游标ID")
     */
    public function checkUserInBlacklist($uid)
    {
        return send_result(intval(table('user_blacklist')->getAllByArray(['uid'=>lianmi_uid(),'block_uid'=>intval($uid)])->toLine()));
    }

    /**
     * 将某用户添加/移出黑名单
     * @ApiDescription(section="User", description="将某用户添加/移出黑名单")
     * @ApiLazyRoute(uri="/user/blacklist_set",method="POST|GET")
     * @ApiParams(name="uid", type="int", nullable=false, description="uid", cnname="游标ID")
     * @ApiParams(name="status", type="int", nullable=false, description="status", cnname="状态")
     */
    public function setUserInBlacklist($uid, $status)
    {
        $current_uid = lianmi_uid();
        $target_uid = intval($uid);
        $status = intval($status);

        if ($status == 1) {
            if ($target_uid == $current_uid) {
                return lianmi_throw('INPUT', '不能将自己加入黑名单');
            }
            $sql = "INSERT IGNORE INTO `user_blacklist` ( `uid` , `block_uid` , `timeline` ) VALUES ( :current_uid , :target_uid , :timeline )";
            run_sql($sql, [':current_uid' => $current_uid, ':target_uid' => $target_uid, ':timeline' => lianmi_now()]);
        } else {
            $sql = "DELETE FROM `user_blacklist` WHERE `uid` = :current_uid AND `block_uid` = :target_uid LIMIT 1";
            run_sql($sql, [':current_uid' => $current_uid, ':target_uid' => $target_uid]);
        }
        return send_result($status);
    }

    

    /**
     * 获得当前用户的黑名单
     * @ApiDescription(section="User", description="获得当前用户的黑名单")
     * @ApiLazyRoute(uri="/user/blacklist",method="POST|GET")
     * @ApiParams(name="since_id", type="int", nullable=false, description="since_id", cnname="游标ID")
     */
    public function getUserBlacklist($since_id = 0)
    {
        $uid = lianmi_uid();
        $since_id = intval($since_id);
        $limit = intval(c('blacklist_per_page'));
        $params = [':uid' => $uid];

        $since_sql_condition = "";
        if ($since_id > 0) {
            $since_sql_condition = " AND `id` < :since_id ";
            $params[':since_id'] = $since_id;
        }
        
        $sql = "SELECT * , `block_uid` as `user` FROM `user_blacklist` WHERE `uid` = :uid {$since_sql_condition} ORDER BY `id` DESC LIMIT {$limit}";

        $data = get_data($sql, $params);
        $data = extend_field($data, 'user', 'user');
        
        $maxid = null; $minid = null;
        if (is_array($data) && count($data) > 0) {
            $maxid = $minid = $data[0]['id'];
            foreach ($data as $item) {
                if ($item['id'] > $maxid) $maxid = $item['id'];
                if ($item['id'] < $minid) $minid = $item['id'];
            }
        }
        return send_result(['blacklist'=>$data , 'count'=>count($data) , 'maxid'=>$maxid , 'minid'=>$minid ]);
    }


    /**
     * 获取当前用户的置顶信息
     * @ApiDescription(section="Feed", description="获取当前用户的首页信息流")
     * @ApiLazyRoute(uri="/timeline/top",method="GET|POST")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function getUserTimelineTop()
    {
        $uid = lianmi_uid();
        $limit = intval(c('feeds_per_page'));

        $sql = "SELECT *,  `uid` as `user` , `forward_group_id` as `group` FROM `feed` 
                WHERE `is_top` = 1 AND `is_forward` = 1 AND `status` = 'published'
                AND (
                    ( `forward_group_id` IN ( SELECT `group_id` FROM `group_member` WHERE `uid` = :uid1 AND `is_vip` = 0 ) AND `is_paid` = 0 ) 
                    OR 
                    ( `forward_group_id` IN ( SELECT `group_id` FROM `group_member` WHERE `uid` = :uid2 AND (`is_vip` = 1 OR `is_author` = 1 ) ) )
                ) 
                GROUP BY `forward_feed_id` ORDER BY `id` DESC LIMIT {$limit}";

        $params = [':uid1' => $uid, ':uid2' => $uid];
        
        $data = get_data($sql, $params);
        $data = extend_field($data, 'user', 'user');
        $data = extend_field($data, 'group', 'group');

        return send_result(isset($data[0]) ? $data[0] : "");
    }

    /**
     * 获取当前用户的首页信息流
     * @ApiDescription(section="Feed", description="获取当前用户的首页信息流")
     * @ApiLazyRoute(uri="/timeline",method="GET|POST")
     * @ApiParams(name="since_id", type="int", nullable=false, description="since_id", cnname="游标ID")
     * @ApiParams(name="filter", type="int", nullable=false, description="filter", cnname="过滤选项")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function getUserTimeline($since_id = 0, $filter = 'all')
    {
        $uid = lianmi_uid();
        $since_id = intval($since_id);
        $limit = intval(c('feeds_per_page'));
        
        $params = [':uid1' => $uid, ':uid2' => $uid];
        $filter_conditions = "";

        if ($filter == 'paid') {
            $filter_conditions .= " AND f.`is_paid` = 1 ";
        }
        if ($filter == 'media') {
            $filter_conditions .= " AND f.`images` != '' ";
        }
        if ($since_id > 0) {
            $filter_conditions .= " AND f.`id` < :since_id ";
            $params[':since_id'] = $since_id;
        }
        
        $sql = "SELECT f.*, f.`uid` as `user`, f.`forward_group_id` as `group` 
                FROM `feed` f 
                WHERE f.`is_top` != 1 AND f.`is_forward` = 1 AND f.`status` = 'published'
                AND (
                    ( f.`forward_group_id` IN ( SELECT gm1.`group_id` FROM `group_member` gm1 WHERE gm1.`uid` = :uid1 AND gm1.`is_vip` = 0 ) AND f.`is_paid` = 0 ) 
                    OR 
                    ( f.`forward_group_id` IN ( SELECT gm2.`group_id` FROM `group_member` gm2 WHERE gm2.`uid` = :uid2 AND (gm2.`is_vip` = 1 OR gm2.`is_author` = 1) ) )
                ) 
                {$filter_conditions} 
                GROUP BY f.`forward_feed_id` 
                ORDER BY f.`id` DESC 
                LIMIT {$limit}";
        
        $data = get_data($sql, $params);
        $data = extend_field($data, 'user', 'user');
        $data = extend_field($data, 'group', 'group');
        
        $maxid = null; $minid = null;
        if (is_array($data) && count($data) > 0) {
            $maxid = $minid = $data[0]['id'];
            foreach ($data as $item) {
                if ($item['id'] > $maxid) $maxid = $item['id'];
                if ($item['id'] < $minid) $minid = $item['id'];
            }
        }
        return send_result(['sql'=> $sql , 'feeds'=>$data , 'count'=>count($data) , 'maxid'=>$maxid , 'minid'=>$minid ]);
    }

    /**
     * 获取当前用户的首页信息流最新ID
     * @ApiDescription(section="Feed", description="获取当前用户的首页信息流最新ID")
     * @ApiLazyRoute(uri="/timeline/lastid",method="GET|POST")
     * @ApiParams(name="filter", type="int", nullable=false, description="filter", cnname="过滤选项")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function getUserTimelineLastId($filter = 'all')
    {
        $uid = lianmi_uid();
        $params = [':uid1' => $uid, ':uid2' => $uid, ':uid3' => $uid];
        $filter_conditions = "";

        if ($filter == 'paid') {
            $filter_conditions .= " AND f.`is_paid` = 1 ";
        }
        if ($filter == 'media') {
            $filter_conditions .= " AND f.`images` != '' ";
        }
        
        $sql = "SELECT f.`id` FROM `feed` f
                WHERE f.`is_top` != 1 AND f.`is_forward` = 1 
                AND (
                    ( f.`forward_group_id` IN ( SELECT gm1.`group_id` FROM `group_member` gm1 WHERE gm1.`uid` = :uid1 AND gm1.`is_vip` = 0 ) AND f.`is_paid` = 0 ) 
                    OR 
                    ( f.`forward_group_id` IN ( SELECT gm2.`group_id` FROM `group_member` gm2 WHERE gm2.`uid` = :uid2 AND (gm2.`is_vip` = 1 OR gm2.`is_author` = 1) ) ) 
                ) 
                {$filter_conditions} 
                GROUP BY f.`forward_feed_id` 
                ORDER BY f.`id` DESC 
                LIMIT 1";
        
        $last_id = get_var($sql, $params);
        return send_result($last_id);
    }

    /**
     * Create a new tag
     * @ApiDescription(section="Tag", description="Create a new tag")
     * @ApiLazyRoute(uri="/tag", method="POST")
     * @ApiParams(name="name", type="string", nullable=false, description="Tag name")
     * @ApiParams(name="slug", type="string", nullable=true, description="Tag slug (optional, auto-generated if empty)")
     * @ApiReturn(type="object", sample="{'code': 0, 'message': 'success', 'data': {'id': 1, 'name': 'Tech', 'slug': 'tech'}}")
     */
    public function createTag($name, $slug = null)
    {
        $name = trim($name);
        if (empty($name)) {
            return lianmi_throw('INPUT', 'Tag name cannot be empty.');
        }

        if (empty($slug)) {
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
            $slug = trim($slug, '-');
        } else {
            $slug = strtolower(trim($slug));
        }
        if (empty($slug)) {
              return lianmi_throw('INPUT', 'Tag slug could not be generated or was empty.');
        }

        try {
            $existingTagByName = get_line("SELECT * FROM `tags` WHERE `name` = :name LIMIT 1", [':name' => $name]);
            if ($existingTagByName) {
                return lianmi_throw('INPUT', 'Tag name already exists.', $existingTagByName);
            }
            $existingTagBySlug = get_line("SELECT * FROM `tags` WHERE `slug` = :slug LIMIT 1", [':slug' => $slug]);
            if ($existingTagBySlug) {
                return lianmi_throw('INPUT', 'Tag slug already exists. Try a different name or slug.', $existingTagBySlug);
            }

            run_sql("INSERT INTO `tags` (`name`, `slug`, `created_at`, `updated_at`) VALUES (:name, :slug, :now, :now)", [
                ':name' => $name,
                ':slug' => $slug,
                ':now' => lianmi_now()
            ]);
            $tag_id = db()->lastId();
            $tag = get_line("SELECT * FROM `tags` WHERE `id` = :id", [':id' => $tag_id]);
            return send_result($tag);
        } catch (\PDOException $e) {
            if ($e->getCode() == 23000) {
                return lianmi_throw('DATABASE', 'Tag name or slug already exists (database constraint).');
            }
            return lianmi_throw('DATABASE', 'Failed to create tag: ' . $e->getMessage());
        }
    }

    /**
     * List tags
     * @ApiDescription(section="Tag", description="List all tags")
     * @ApiLazyRoute(uri="/tags", method="GET")
     * @ApiParams(name="q", type="string", nullable=true, description="Search term for tag name")
     * @ApiReturn(type="array", sample="[{'id': 1, 'name': 'Tech', 'slug': 'tech'}]")
     */
    public function listTags($q = null)
    {
        $sql = "SELECT * FROM `tags`";
        $params = [];
        if (!empty($q)) {
            $sql .= " WHERE `name` LIKE :q OR `slug` LIKE :q";
            $params[':q'] = '%' . trim($q) . '%';
        }
        $sql .= " ORDER BY `name` ASC";
        $tags = get_data($sql, $params);
        return send_result($tags);
    }

    /**
     * Get current user's draft feeds
     * @ApiDescription(section="Feed", description="Get my draft feeds")
     * @ApiLazyRoute(uri="/feeds/drafts", method="GET")
     * @ApiParams(name="since_id", type="int", nullable=true, description="For pagination, fetch drafts older than this ID")
     * @ApiReturn(type="array", sample="[{'id': 1, 'text': 'Draft content...', 'status': 'draft'}]")
     */
    public function getMyDrafts($since_id = 0)
    {
        $uid = lianmi_uid();
        $since_id = intval($since_id);
        $limit = intval(c('feeds_per_page', 20));

        $sql = "SELECT * FROM `feed` WHERE `uid` = :uid AND `status` = 'draft' AND `is_delete` != 1";
        $params = [':uid' => $uid];

        if ($since_id > 0) {
            $sql .= " AND `id` < :since_id";
            $params[':since_id'] = $since_id;
        }
        $sql .= " ORDER BY `id` DESC LIMIT " . $limit;

        $drafts = get_data($sql, $params);

        $maxid = null; $minid = null;
        if (is_array($drafts) && count($drafts) > 0) {
            $maxid = $minid = $drafts[0]['id'];
            foreach ($drafts as $item) {
                if ($item['id'] > $maxid) $maxid = $item['id'];
                if ($item['id'] < $minid) $minid = $item['id'];
            }
        }
       return send_result(['feeds'=>$drafts , 'count'=>count($drafts) , 'maxid'=>$maxid , 'minid'=>$minid ]);
    }

    /**
     * Get current user's scheduled feeds
     * @ApiDescription(section="Feed", description="Get my scheduled feeds")
     * @ApiLazyRoute(uri="/feeds/scheduled", method="GET")
     * @ApiParams(name="since_id", type="int", nullable=true, description="For pagination, fetch items older than this ID")
     * @ApiReturn(type="array", sample="[{'id': 1, 'text': 'Scheduled content...', 'status': 'scheduled', 'scheduled_at': 'YYYY-MM-DD HH:MM:SS'}]")
     */
    public function getMyScheduledFeeds($since_id = 0)
    {
        $uid = lianmi_uid();
        $since_id = intval($since_id);
        $limit = intval(c('feeds_per_page', 20));

        $sql = "SELECT * FROM `feed` WHERE `uid` = :uid AND `status` = 'scheduled' AND `is_delete` != 1";
        $params = [':uid' => $uid];

        if ($since_id > 0) {
            $sql .= " AND `id` < :since_id";
            $params[':since_id'] = $since_id;
        }
        $sql .= " ORDER BY `scheduled_at` ASC LIMIT " . $limit;

        $scheduled_feeds = get_data($sql, $params);

        $maxid = null; $minid = null;
        if (is_array($scheduled_feeds) && count($scheduled_feeds) > 0) {
            $maxid = $minid = $scheduled_feeds[0]['id'];
            foreach ($scheduled_feeds as $item) {
                if ($item['id'] > $maxid) $maxid = $item['id'];
                if ($item['id'] < $minid) $minid = $item['id'];
            }
        }
       return send_result(['feeds'=>$scheduled_feeds , 'count'=>count($scheduled_feeds) , 'maxid'=>$maxid , 'minid'=>$minid ]);
    }

    /**
     * 获得和某个用户的聊天记录最新id
     * @ApiDescription(section="Message", description="获得和某个用户的聊天记录最新id")
     * @ApiLazyRoute(uri="/message/lastest_id/@to_uid",method="GET|POST")
     * @ApiParams(name="to_uid", type="int", nullable=false, description="to_uid", check="check_uint", cnname="用户ID")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function getMessageLatest($to_uid)
    {
        $uid = lianmi_uid();
        $to_uid = intval($to_uid);
        return send_result(intval(get_var("SELECT MAX(`id`) FROM `message` WHERE `uid` = :uid AND ( `to_uid` = :to_uid OR `from_uid` = :to_uid2 )", [':uid' => $uid, ':to_uid' => $to_uid, ':to_uid2' => $to_uid])));
    }

    /**
     * 获得当前用户未读信息数量
     * @ApiDescription(section="Message", description="获得当前用户未读信息数量")
     * @ApiLazyRoute(uri="/message/unread",method="GET|POST")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function getMessageUnreadCount()
    {
        $uid = lianmi_uid();
        return send_result(intval(get_var("SELECT COUNT(*) FROM `message` WHERE `uid` = :uid AND `is_read` = 0", [':uid' => $uid])));
    }

    /**
     * 获得当前用户的最新消息分组列表页面
     * @ApiDescription(section="Message", description="获得当前用户的最新消息分组列表页面")
     * @ApiLazyRoute(uri="/message/grouplist",method="GET|POST")
     * @ApiParams(name="since_id", type="int", nullable=false, description="since_id", cnname="游标ID")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function getMessageGroupList($since_id = 0)
    {
        $uid = lianmi_uid();
        $since_id = intval($since_id);
        $limit = intval(c('message_group_per_page'));
        $params = [':uid' => $uid];

        $total = get_var("SELECT COUNT(*) FROM `message_group` WHERE `uid` = :uid", [':uid' => $uid]);
        
        $since_sql_condition = "";
        if ($since_id > 0) {
            $since_sql_condition = " AND `id` < :since_id ";
            $params[':since_id'] = $since_id;
        }
        
        $sql = "SELECT * , `from_uid` as `from` , `to_uid` as `to` FROM `message_group` WHERE `uid` = :uid {$since_sql_condition} ORDER BY `id` DESC  LIMIT {$limit}";

        $data = get_data($sql, $params);
        $data = extend_field($data, 'from', 'user');
        $data = extend_field($data, 'to', 'user');
        
        $maxid = null; $minid = null;
        if (is_array($data) && count($data) > 0) {
            $maxid = $minid = $data[0]['id'];
            foreach ($data as $item) {
                if ($item['id'] > $maxid) $maxid = $item['id'];
                if ($item['id'] < $minid) $minid = $item['id'];
            }
        }
        return send_result(['messages'=>$data , 'count'=>count($data) , 'maxid'=>$maxid , 'minid'=>$minid , 'total' => $total ]);
    }

    /**
     * 获得和某个用户的聊天记录
     * @ApiDescription(section="Message", description="获得和某个用户的聊天记录")
     * @ApiLazyRoute(uri="/message/history/@to_uid",method="GET|POST")
     * @ApiParams(name="to_uid", type="int", nullable=false, description="to_uid", check="check_uint", cnname="用户ID")
     * @ApiParams(name="since_id", type="int", nullable=false, description="since_id", cnname="游标ID")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function getMessageHistory($to_uid, $since_id = 0)
    {
        $uid = lianmi_uid();
        $to_uid = intval($to_uid);
        $since_id = intval($since_id);
        $limit = intval(c('history_per_page'));

        $params_count = [':uid' => $uid, ':to_uid1' => $to_uid, ':to_uid2' => $to_uid];
        $total = get_var("SELECT COUNT(*) FROM `message` WHERE `uid` = :uid AND ( `to_uid` = :to_uid1 OR `from_uid` = :to_uid2 )", $params_count);
        
        $params_select = [':uid' => $uid, ':to_uid1' => $to_uid, ':to_uid2' => $to_uid];
        $since_sql_condition = "";
        if ($since_id > 0) {
            $since_sql_condition = " AND `id` < :since_id ";
            $params_select[':since_id'] = $since_id;
        }
        
        $sql = "SELECT * FROM `message` WHERE `uid` = :uid AND ( `to_uid` = :to_uid1 OR `from_uid` = :to_uid2 ) {$since_sql_condition} ORDER BY `id` DESC  LIMIT {$limit}";
        $data = get_data($sql, $params_select);
        
        $maxid = null; $minid = null;
        if (is_array($data) && count($data) > 0) {
            $maxid = $minid = $data[0]['id'];
            foreach ($data as $item) {
                if ($item['id'] > $maxid) $maxid = $item['id'];
                if ($item['id'] < $minid) $minid = $item['id'];
            }

            if ($since_id == 0) {
                $params_update = [':uid' => $uid, ':to_uid1' => $to_uid, ':to_uid2' => $to_uid];
                run_sql("UPDATE `message` SET `is_read` = 1 WHERE `is_read` = 0 AND `uid` = :uid AND ( `to_uid` = :to_uid1 OR `from_uid` = :to_uid2 )", $params_update);
                run_sql("UPDATE `message_group` SET `is_read` = 1 WHERE `is_read` = 0 AND `uid` = :uid AND ( `to_uid` = :to_uid1 OR `from_uid` = :to_uid2 )", $params_update);
            }
        }
        return send_result(['messages'=>$data , 'count'=>count($data) , 'maxid'=>$maxid , 'minid'=>$minid , 'total' => $total ]);
    }

    /**
     * 向某用户发送私信
     * @ApiDescription(section="Message", description="向某用户发送私信")
     * @ApiLazyRoute(uri="/message/send/@to_uid",method="GET|POST")
     * @ApiParams(name="to_uid", type="int", nullable=false, description="to_uid", check="check_uint", cnname="用户ID")
     * @ApiParams(name="text", type="string", nullable=false, description="text", check="check_not_empty", cnname="私信内容")

     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function sendMessage($to_uid, $text)
    {
    public function sendMessage($to_uid, $text)
    {
        $current_uid = lianmi_uid();
        $to_uid = intval($to_uid);

        if ($to_uid == $current_uid) {
            return lianmi_throw('INPUT', '不要自己给自己发私信啦');
        }
        
        if (table('user_blacklist')->getAllByArray(['uid'=>$to_uid,'block_uid'=>$current_uid])->toLine() || 
            table('user_blacklist')->getAllByArray(['uid'=>$current_uid ,'block_uid'=>$to_uid])->toLine()) {
            return lianmi_throw('AUTH', '你或者对方在黑名单中');
        }

        $now = lianmi_now();
        
        $params_sender_msg = [':uid' => $current_uid, ':to_uid' => $to_uid, ':from_uid' => $current_uid, ':text' => $text, ':timeline' => $now, ':is_read' => 1];
        run_sql("INSERT INTO `message` ( `uid` , `to_uid` , `from_uid` , `text` , `timeline` , `is_read` ) VALUES ( :uid , :to_uid , :from_uid , :text , :timeline , :is_read )", $params_sender_msg);

        $params_receiver_msg = [':uid' => $to_uid, ':to_uid' => $to_uid, ':from_uid' => $current_uid, ':text' => $text, ':timeline' => $now, ':is_read' => 0];
        run_sql("INSERT INTO `message` ( `uid` , `to_uid` , `from_uid` , `text` , `timeline` , `is_read` ) VALUES ( :uid , :to_uid , :from_uid , :text , :timeline , :is_read )", $params_receiver_msg);

        $params_delete_group = [':to_uid1' => $to_uid, ':from_uid1' => $current_uid, ':to_uid2' => $current_uid, ':from_uid2' => $to_uid];
        run_sql("DELETE FROM `message_group` WHERE ( `to_uid` = :to_uid1 AND `from_uid` = :from_uid1 ) OR ( `to_uid` = :to_uid2 AND `from_uid` = :from_uid2 ) LIMIT 2", $params_delete_group);

        $params_sender_group = [':uid' => $current_uid, ':to_uid' => $to_uid, ':from_uid' => $current_uid, ':text' => $text, ':timeline' => $now, ':is_read' => 1];
        run_sql("REPLACE INTO `message_group` ( `uid` , `to_uid` , `from_uid` , `text` , `timeline` , `is_read` ) VALUES ( :uid , :to_uid , :from_uid , :text , :timeline , :is_read )", $params_sender_group);
        
        $params_receiver_group = [':uid' => $to_uid, ':to_uid' => $to_uid, ':from_uid' => $current_uid, ':text' => $text, ':timeline' => $now, ':is_read' => 0];
        run_sql("REPLACE INTO `message_group` ( `uid` , `to_uid` , `from_uid` , `text` , `timeline` , `is_read` ) VALUES ( :uid , :to_uid , :from_uid , :text , :timeline , :is_read )", $params_receiver_group);

        return send_result('done');
    }

    /**
     * 刷新服务器端用户数据
     * @ApiDescription(section="Message", description="刷新服务器端用户数据")
     * @ApiLazyRoute(uri="/user/refresh",method="GET|POST")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function refreshUserData()
    {
        $uid = lianmi_uid();
        $user = get_line("SELECT * FROM `user` WHERE `id` = :uid LIMIT 1", [':uid' => $uid]);
        if (!$user) {
            return lianmi_throw("INPUT", "用户不存在");
        }
        
        $user['uid'] = $user['id'];
        $user['token'] = session_id();
        $user = array_merge($user, get_group_info($user['id']));
        return send_result($user);
    }

    private function processFeedTags($feed_id, $tag_names)
    {
        run_sql("DELETE FROM `feed_tags` WHERE `feed_id` = :feed_id", [':feed_id' => $feed_id]);

        if (empty($tag_names)) {
            return;
        }

        $tag_ids_to_associate = [];
        foreach ($tag_names as $name) {
            $name = trim($name);
            if (empty($name)) continue;

            $tag = get_line("SELECT `id`, `slug` FROM `tags` WHERE `name` = :name LIMIT 1", [':name' => $name]);
            if ($tag) {
                $tag_ids_to_associate[] = $tag['id'];
            } else {
                $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
                $slug = trim($slug, '-');

                if (empty($slug)) {
                    continue;
                }

                try {
                     $existingTagBySlug = get_line("SELECT `id` FROM `tags` WHERE `slug` = :slug LIMIT 1", [':slug' => $slug]);
                     if($existingTagBySlug){
                         continue;
                     }

                    run_sql("INSERT INTO `tags` (`name`, `slug`, `created_at`, `updated_at`) VALUES (:name, :slug, :now, :now)", [
                        ':name' => $name,
                        ':slug' => $slug,
                        ':now' => lianmi_now()
                    ]);
                    $new_tag_id = db()->lastId();
                    if ($new_tag_id) {
                        $tag_ids_to_associate[] = $new_tag_id;
                    }
                } catch (\PDOException $e) {
                    if ($e->getCode() == 23000) {
                        $retryTag = get_line("SELECT `id` FROM `tags` WHERE `name` = :name LIMIT 1", [':name' => $name]);
                        if ($retryTag) $tag_ids_to_associate[] = $retryTag['id'];
                    }
                }
            }
        }

        foreach (array_unique($tag_ids_to_associate) as $tag_id) {
            run_sql("INSERT IGNORE INTO `feed_tags` (`feed_id`, `tag_id`) VALUES (:feed_id, :tag_id)", [
                ':feed_id' => $feed_id,
                ':tag_id' => $tag_id
            ]);
        }
    }
}

[end of www/api/controller/AuthedApiController.php]
