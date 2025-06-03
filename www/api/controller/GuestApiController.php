<?php
namespace Lazyphp\Controller;
set_time_limit( 80 );

class GuestApiController
{
	public function __construct()
    {
        // Guest 下的接口支持token，但不验证。
        // 不认 cookie 带来的 php sessionid
        $token = t(v('token'));
        if( strlen( $token ) > 0 )
        {
            session_id( $token );
            session_start();
        }
        
        $stoken = t(v('stoken'));
        if( strlen( $stoken ) > 0 ) login_by_stoken( $stoken );
    }

    /**
     * 用户注册接口
     * @ApiDescription(section="User", description="用户注册接口")
     * @ApiLazyRoute(uri="/user/register",method="POST|GET")
     * @ApiParams(name="email", type="string", nullable=false, description="email", check="check_not_empty", cnname="email")
     * @ApiParams(name="nickname", type="string", nullable=false, description="nickname", check="check_not_empty", cnname="用户昵称")
    * @ApiParams(name="username", type="string", nullable=false, description="username", check="check_not_empty", cnname="用户唯一ID")
    * @ApiParams(name="password", type="string", nullable=false, description="password", check="check_not_empty", cnname="用户密码")
    * @ApiParams(name="address", type="string", nullable=false, description="address",  cnname="钱包地址")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function register( $email , $nickname , $username , $password, $address = '' )
    {
        $email = strtolower(trim($email));
        $nickname = mb_substr(trim($nickname), 0, 15, 'UTF-8');
        $username = trim($username);
        $address = trim($address);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return lianmi_throw("INPUT", "Email格式不正确");
        }
        if (get_var("SELECT COUNT(*) FROM `user` WHERE `email` = :email", [':email' => $email]) > 0) {
            return lianmi_throw("INPUT", "email地址已被注册");
        }
        if (mb_strlen($username, 'UTF-8') < 3) {
            return lianmi_throw("INPUT", "UserName长度不能少于3");
        }
        if (mb_strlen($username, 'UTF-8') > 15) {
            return lianmi_throw("INPUT", "UserName长度不能大于15");
        }
        if (!preg_match('/^([A-Za-z]+[A-Za-z0-9_\-]*)$/is', $username)) {
            return lianmi_throw("INPUT", "UserID格式错误，只能字母开始，并包含数字、字母、减号和下划线，长度不能少于3");
        }
        if (get_var("SELECT COUNT(*) FROM `user` WHERE `username` = :username", [':username' => $username]) > 0) {
            return lianmi_throw("INPUT", "UserName已被占用");
        }
        if (in_array(strtolower($nickname), c('forbiden_nicknames'))) {
            return lianmi_throw('INPUT', '此用户昵称已被系统保留，请重新选择');
        }
        if (in_array(strtolower($username), c('forbiden_usernames'))) {
            return lianmi_throw('INPUT', '此UserName已被系统保留，请重新选择');
        }
        if (strlen($password) < 6) {
            return lianmi_throw('INPUT', '密码长度不能短于6位');
        }
        
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $now = lianmi_now();

        $sql = "INSERT INTO `user` ( `email` , `nickname` , `username` , `password` , `address` , `timeline` ) VALUES ( :email, :nickname, :username, :password, :address, :timeline )";
        $params = [
            ':email' => $email,
            ':nickname' => $nickname,
            ':username' => $username,
            ':password' => $hash,
            ':address' => $address,
            ':timeline' => $now
        ];
        run_sql($sql, $params);

        $user_id = db()->lastId();
        $user_response = [
            'id' => $user_id,
            'email' => $email,
            'username' => $username,
            'nickname' => $nickname
        ];
        return send_result($user_response);
    }

    /**
     * 用户登入接口
     * @ApiDescription(section="User", description="用户登入接口")
     * @ApiLazyRoute(uri="/user/login",method="POST|GET")
     * @ApiParams(name="email", type="string", nullable=false, description="email", check="check_not_empty", cnname="email")
     * @ApiParams(name="password", type="string", nullable=false, description="password", check="check_not_empty", cnname="用户密码")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function login( $email , $password )
    {
        $email = strtolower(trim($email));
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return lianmi_throw("INPUT", "Email格式不正确");
        }

        $user = get_line("SELECT * FROM `user` WHERE `email` = :email LIMIT 1", [':email' => $email]);
        if (!$user) {
            return lianmi_throw("INPUT", "Email地址不存在或者密码错误");
        }

        if (!password_verify($password, $user['password'])) {
            return lianmi_throw("INPUT", "Email地址不存在或者密码错误");
        }
        
        unset($user['password']);

        if (intval($user['level']) < 1) {
            return lianmi_throw("INPUT", "账号不存在或已被限制登入");
        }

        session_start();
        session_regenerate_id(true);
        
        $user['uid'] = $user['id'];
        $user['token'] = session_id();

        // get_group_info has been refactored to use parameterized queries if it makes DB calls.
        $user = array_merge($user, get_group_info($user['id']));

        foreach ([ 'uid' , 'email' , 'nickname' , 'username' , 'level' , 'avatar' ] as $field) {
            if (isset($user[$field])) { // Ensure key exists before assigning to session
                 $_SESSION[$field] = $user[$field];
            }
        }
        return send_result($user);
    }

    /**
     * 显示图片
     * @TODO 此接口不需要登入，以后会使用云存储或者x-send来替代
     * @ApiDescription(section="Global", description="显示图片接口")
     * @ApiLazyRoute(uri="/image/@uid/@inner_path",method="GET|POST")
     * @ApiParams(name="uid", type="string", nullable=false, description="uid", check="check_not_empty", cnname="图片路径")
     * @ApiParams(name="inner_path", type="string", nullable=false, description="inner_path", check="check_not_empty", cnname="图片路径")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function showImage( $uid , $inner_path )
    {
        $path = $uid .'/' . $inner_path;
        if( !$content = storage()->read( $path )) return lianmi_throw( 'FILE' , '文件数据不存在' );
        $mime = storage()->getMimetype($path);

        header('Content-Type: ' . $mime );
        echo $content;

        return true;
        
    }

    /**
     * 显示栏目基本信息
     * 此接口不需要登入
     * @ApiDescription(section="Group", description="显示栏目基本信息接口")
     * @ApiLazyRoute(uri="/group/detail/@id",method="GET|POST")
     * @ApiParams(name="id", type="int", nullable=false, description="id", check="check_uint", cnname="栏目ID")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function getGroupDetail( $id )
    {
        $id = intval($id);
        $group = get_line("SELECT * FROM `group` WHERE `id` = :id LIMIT 1", [':id' => $id]);
        if (!$group) {
            return lianmi_throw('INPUT', 'ID对应的栏目已删除或不存在');
        }
        return send_result($group);
    }

    /**
     * 获得栏目列表
     * 此接口不需要登入，注意这是一个不完全列表
     * @ApiDescription(section="Group", description="获得栏目列表")
     * @ApiLazyRoute(uri="/group/top100",method="GET|POST")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function getGroupTop100()
    {
        // No parameters, query is safe as is.
        return send_result(get_data("SELECT * FROM `group` WHERE `is_active` = 1 ORDER BY `promo_level` DESC , `member_count` DESC , `id` DESC LIMIT 100"));
    }

    /**
     * 获取用户基本信息
     * 此接口不需要登入
     * @ApiDescription(section="User", description="获取用户基本信息接口")
     * @ApiLazyRoute(uri="/user/detail(/@id)",method="GET|POST")
     * @ApiParams(name="id", type="int", nullable=false, description="id", cnname="用户ID")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function getUserDetail( $id = null )
    {
        $current_uid = lianmi_uid();
        if ($id === null && $current_uid > 0) {
            $id = $current_uid;
        }
        $id = abs(intval($id));

        $params = [':id' => $id];
        if ($current_uid > 0 && $current_uid == $id) {
            $sql = "SELECT * FROM `user` WHERE `id` = :id AND `level` > 0 LIMIT 1";
        } else {
            $fields = c('user_normal_fields');
            // Basic sanitization for fields string to prevent breaking the query, though it comes from config.
            $fields = preg_replace('/[^a-zA-Z0-9_,*`]/', '', $fields); 
            if (empty($fields)) $fields = '*'; // Fallback
            $sql = "SELECT " . $fields .  " FROM `user` WHERE `id` = :id AND `level` > 0 LIMIT 1";
        }
        
        $user = get_line($sql, $params);
        if (!$user) {
            return lianmi_throw('INPUT', 'ID对应的用户已删除或不存在');
        }
        
        if (isset($user['password'])) unset($user['password']);
        return send_result($user);
    }

    /**
     * 获取内容的全部内容
     * 此接口不需要登入
     * @ApiDescription(section="Feed", description="获取内容的全部内容")
     * @ApiLazyRoute(uri="/feed/detail/@id",method="GET|POST")
     * @ApiParams(name="id", type="int", nullable=false, description="id", check="check_uint", cnname="内容ID")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function getFeedDetail( $id )
    {
        $id = intval($id);
        $current_uid = lianmi_uid();

        $feed = get_line("SELECT *, `uid` as `user` , `group_id` as `group` FROM `feed` WHERE `id` = :id AND `is_delete` = 0 LIMIT 1", [':id' => $id]);
        if (!$feed) {
            return lianmi_throw('INPUT', 'ID对应的内容不存在或者你没有权限阅读');
        }
        
        $can_see = true;
        if ($feed['is_paid'] == 1) {
            $can_see = false;
            if ($current_uid > 0) {
                if ($feed['is_forward'] == 1) {
                    $params_member = [':group_id' => $feed['forward_group_id'], ':uid' => $current_uid];
                    $member_ship = get_line("SELECT * FROM `group_member` WHERE `group_id` = :group_id AND `uid` = :uid LIMIT 1", $params_member);
                    if ($member_ship && ($member_ship['is_author'] == 1 || $member_ship['is_vip'] == 1)) {
                        $can_see = true;
                    }
                } else { // Original post
                    if ($current_uid == $feed['uid']) {
                        $can_see = true;
                    }
                }
            }
        }

        if (!$can_see) {
            return lianmi_throw('AUTH', '该内容为付费内容，仅限VIP订户查看');
        }

        // Increment view_count if feed is published and viewable
        if ($feed && isset($feed['status']) && $feed['status'] == 'published' && $can_see) {
            run_sql("UPDATE `feed` SET `view_count` = `view_count` + 1 WHERE `id` = :id", [':id' => $id]);
            // Optionally, update $feed['view_count'] for the response if desired, though not critical for analytics.
            // $feed['view_count'] = (isset($feed['view_count']) ? $feed['view_count'] + 1 : 1);
        }
        
        if ($feed['is_forward'] == 1) $feed['group'] = $feed['forward_group_id']; // Ensure 'group' field has the correct group ID for extend_field
        
        // extend_field_oneline has been refactored
        $feed = extend_field_oneline($feed, 'user', 'user');
        if ($feed['group'] > 0) { // Only extend group if group ID is valid
             $feed = extend_field_oneline($feed, 'group', 'group');
        } else {
            $feed['group'] = null; // Or some default for no group
        }

        if ($feed && $can_see && isset($feed['post_type']) && $feed['post_type'] == 'poll') {
            $poll_data = get_line("SELECT * FROM `polls` WHERE `feed_id` = :feed_id LIMIT 1", [':feed_id' => $feed['id']]);
            if ($poll_data) {
                $options = get_data("
                    SELECT po.id, po.option_text, COUNT(pv.id) as vote_count
                    FROM `poll_options` po
                    LEFT JOIN `poll_votes` pv ON po.id = pv.poll_option_id
                    WHERE po.poll_id = :poll_id
                    GROUP BY po.id, po.option_text
                    ORDER BY po.id ASC
                ", [':poll_id' => $poll_data['id']]);

                $poll_data['options'] = $options ? $options : [];

                $current_user_id = lianmi_uid(); // This will be 0 if guest
                $poll_data['current_user_vote'] = null; // Default to null (no vote or guest)
                if ($current_user_id > 0) {
                    $user_vote = get_line("
                        SELECT poll_option_id
                        FROM `poll_votes`
                        WHERE poll_id = :poll_id AND user_id = :user_id
                        LIMIT 1
                    ", [':poll_id' => $poll_data['id'], ':user_id' => $current_user_id]);
                    if ($user_vote) {
                        $poll_data['current_user_vote'] = intval($user_vote['poll_option_id']);
                    }
                }
                $feed['poll_details'] = $poll_data;
            }
        }
        
        return send_result($feed);
    }

    /**
     * 获取内容评论列表
     * @ApiDescription(section="Feed", description="对内容发起评论")
     * @ApiLazyRoute(uri="/feed/comment/list/@id",method="GET|POST")
     * @ApiParams(name="since_id", type="int", nullable=false, description="since_id", cnname="游标ID")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function listFeedComment( $id , $since_id = 0  )
    {
        $id = intval($id);
        $current_uid = lianmi_uid();
        $since_id = intval($since_id);

        $feed = get_line("SELECT *, `uid` as `user_obj` , `group_id` as `group_obj` FROM `feed` WHERE `id` = :id AND `is_delete` = 0 LIMIT 1", [':id' => $id]);
        if (!$feed) {
            return lianmi_throw('INPUT', 'ID对应的内容不存在或者你没有权限阅读');
        }
        
        $group_id = $feed['is_forward'] == 1 ? $feed['forward_group_id'] : $feed['group_id'];
        $can_see = true; // Assume can see by default
        
        if ($feed['is_paid'] == 1) {
            $can_see = false; // Paid content, requires auth
            if ($current_uid > 0) {
                if ($feed['is_forward'] == 1 && $group_id > 0) {
                    $member_ship = get_line("SELECT * FROM `group_member` WHERE `group_id` = :group_id AND `uid` = :uid LIMIT 1", [':group_id' => $group_id, ':uid' => $current_uid]);
                    if ($member_ship && ($member_ship['is_author'] == 1 || $member_ship['is_vip'] == 1)) {
                        $can_see = true;
                    }
                } elseif ($current_uid == $feed['uid']) { // Original paid content, owner can see
                    $can_see = true;
                }
            }
        }

        if (!$can_see) {
            return lianmi_throw('AUTH', '没有权限查看此内容的评论，可使用有权限的账号登入后查看');
        }
        
        $params = [':feed_id' => $id];
        $since_sql_condition = "";
        if ($since_id > 0) {
            $since_sql_condition = " AND `id` < :since_id ";
            $params[':since_id'] = $since_id;
        }
        
        $totalcount = get_var("SELECT COUNT(*) FROM `comment` WHERE `feed_id` = :feed_id AND `is_delete` = 0", [':feed_id' => $id]);
        $limit = intval(c('comments_per_feed'));
        
        $sql = "SELECT *, `uid` as `user` , `feed_id` as `feed` FROM `comment` WHERE `feed_id` = :feed_id AND `is_delete` = 0 {$since_sql_condition} ORDER BY `id` DESC LIMIT {$limit}";
        
        $data = get_data($sql, $params);
        if ($data) {
            $data = extend_field($data, 'user', 'user');
            // $data = extend_field($data, 'feed', 'feed'); // Extending 'feed' seems redundant here as we are listing comments for a known feed_id
        } else {
            $data = [];
        }

        $maxid = null; $minid = null;
        if (is_array($data) && count($data) > 0) {
            $maxid = $minid = $data[0]['id'];
            foreach ($data as $item) {
                if ($item['id'] > $maxid) $maxid = $item['id'];
                if ($item['id'] < $minid) $minid = $item['id'];
            }
        }
        return send_result(['comments'=>$data , 'count'=>count($data) , 'maxid'=>$maxid , 'minid'=>$minid , 'total' => $totalcount, 'comments_per_feed'=>c('comments_per_feed') ]);
    }

    /**
     * 获取用户内容列表
     * 此接口不需要登入
     * @ApiDescription(section="User", description="获取用户内容列表")
     * @ApiLazyRoute(uri="/user/feed/@id",method="GET|POST")
     * @ApiParams(name="id", type="int", nullable=false, description="id", check="check_uint", cnname="用户ID")
     * @ApiParams(name="since_id", type="int", nullable=false, description="since_id", cnname="游标ID")
     * @ApiParams(name="filter", type="int", nullable=false, description="filter", cnname="过滤选项")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function getUserFeed( $id , $since_id = 0 , $filter = 'all')
    {
        $id = intval($id);
        $current_uid = lianmi_uid(); // Can be 0 if guest
        $since_id = intval($since_id);
        $limit = intval(c('feeds_per_page'));

        $params = [':uid' => $id];
        $filter_conditions = " AND `is_forward` = '0' AND `uid` = :uid ";
        
        // Paid content visibility: only if is_paid = 0 OR current user is the owner of the feed
        $filter_conditions .= " AND ( `is_paid` = 0 OR `uid` = :current_uid_for_paid_check )";
        $params[':current_uid_for_paid_check'] = $current_uid;


        if ($filter == 'paid') {
            // If filtering for paid, this overrides the general visibility rule for paid posts if user is not owner
            // However, the main WHERE clause already handles visibility, so this might be redundant or needs clarification.
            // For now, let's assume it means "show only paid posts that I am allowed to see".
            $filter_conditions .= " AND `is_paid` = 1 ";
        }
        if ($filter == 'media') {
            $filter_conditions .= " AND `images` != '' ";
        }
        if ($since_id > 0) {
            $filter_conditions .= " AND `id` < :since_id ";
            $params[':since_id'] = $since_id;
        }

        $sql = "SELECT *, `uid` as `user` , `forward_group_id` as `group` FROM `feed` WHERE `is_delete` != 1 {$filter_conditions} ORDER BY `id` DESC LIMIT {$limit}";

        $data = get_data($sql, $params);
        $data = extend_field($data, 'user', 'user');
        // `group` here would be `forward_group_id` which is 0 for non-forwarded feeds.
        // So extending group might not be very useful unless structure changes or it's a placeholder.
        // If item.group_id (original group_id, not forward_group_id) is relevant, that should be used.
        // For now, keeping original logic of trying to extend based on `forward_group_id`.
        $data = extend_field($data, 'group', 'group'); 
        
        $maxid = null; $minid = null;
        if (is_array($data) && count($data) > 0) {
            $maxid = $minid = $data[0]['id'];
            foreach ($data as $item) {
                if ($item['id'] > $maxid) $maxid = $item['id'];
                if ($item['id'] < $minid) $minid = $item['id'];
            }
        }
        return send_result( ['feeds'=>$data , 'count'=>count($data) , 'maxid'=>$maxid , 'minid'=>$minid ] );
    }



    /**
     * 检查合约数据，并更新setGroup的内容
     * @TODO 这个接口性能非常的慢，稍后分离出去做成独立服务，同时需要串行化，不管是通过加锁还是队列。
     * @ApiDescription(section="Group", description="检查栏目购买数据")
     * @ApiLazyRoute(uri="/group/contract/check/@id",method="GET|POST")
     * @ApiParams(name="id", type="int", nullable=false, description="id", check="check_uint", cnname="栏目ID")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function checkGroupPay( $id )
    {
        $abi = json_decode( file_get_contents( AROOT . DS . 'contract' . DS . 'build' . DS . 'lianmi.abi' ) );
        $contract = new \Web3\Contract( c('web3_network') , $abi );
        
        $contract->at( c('contract_address') )->call( 'feeOf' , $id , function( $error , $data ) use( $id , $contract )
        {
            if( $error != null )
            {
                return lianmi_throw( 'CONTRACT' , '合约调用失败：' . $error->getMessage() );
            }
            else
            {
                $data = reset( $data );
                if( $data->compare( new \phpseclib\Math\BigInteger('1000000000000000')) >= 0 )
                {
                    // 当支付过超过 0.001 eth 时
                    if( !$group_info = db()->getData("SELECT * FROM `group` WHERE `id` = :id LIMIT 1", [':id' => intval($id)])->toLine())
                    {
                        return lianmi_throw( 'INPUT' , '栏目不存在或已被删除' );
                    }

                    // 只发布一次数据
                    if( $group_info['is_paid'] == 1 )
                    {
                        return lianmi_throw( 'INPUT' , '栏目完成过初始化，如合约发生问题，请联系管理员' );
                    }

                    $seller_address = @c('sellers')[$group_info['seller_uid']];

                    if( strlen( $seller_address ) < 1 ) $seller_address = $group_info['author_address'];

                    // 调用命令行，写入合约
                    // --groupid=1 --price=10000000000000000 --author_address=0xF05949e6d0Ed5148843Ce3f26e0f747095549BB4 --seller_address=0xF05949e6d0Ed5148843Ce3f26e0f747095549BB4
                    
                    $exec_command = 'node --harmony ' . AROOT . DS . 'contract' . DS . 'group.js --groupid=' . escapeshellarg(strval(intval( $group_info['id'] ))) . ' --price=' . escapeshellarg(strval(bigintval( $group_info['price_wei'] ))) . ' --author_address=' . escapeshellarg($group_info['author_address']) . ' --seller_address=' . escapeshellarg($seller_address);
                    $lastline = exec( $exec_command , $output , $val );
                    
                    $ret = strtolower(t(join( "" , $output )));

                    if( t($lastline) == 'ok' )
                    {
                        // 在调用检测下结果
                        $contract->at( c('contract_address') )->call( 'settingsOf' , $group_info['id'] , function( $error , $data ) use( $group_info )
                        {
                            if( strtolower($data['author']) == strtolower($group_info['author_address']) )
                            {
                                // 设置正确
                                db()->runSql( "UPDATE `group` SET `is_paid` = 1 , `is_active` = 1 WHERE `id` = :id LIMIT 1", [':id' => intval($group_info['id'])] );

                                // 将支付人加入到栏目里
                                //db()->


                                return send_result( "done" );
                            }
                        });
                    }
                    else
                    {
                        return lianmi_throw( 'CONTRACT' , '合约调用失败:'.$ret );
                    }



                }
                else
                {
                    return lianmi_throw( 'CONTRACT' , '支付的金额不足'.$data );
                }
                //return var_dump( $data) );
            }
        });

    }

    /**
     * 退出当前用户
     * @ApiLazyRoute(uri="/logout",method="GET|POST")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function logout()
    {
        if( isset( $_SESSION ) )
            foreach( $_SESSION as $key => $value )
            {
                unset( $_SESSION[$key] );
            }

        return  send_result( intval(!isset( $_SESSION['uid'] )) );
    }

    /**
     * 获取栏目的免费内容
     * @ApiDescription(section="Group", description="检查栏目购买数据")
     * @ApiLazyRoute(uri="/group/feed2/@id",method="GET|POST")
     * @ApiParams(name="id", type="int", nullable=false, description="id", check="check_uint", cnname="栏目ID")
     * @ApiParams(name="since_id", type="int", nullable=false, description="since_id", cnname="游标ID")
     * @ApiParams(name="filter", type="int", nullable=false, description="filter", cnname="过滤选项")
     * @ApiReturn(type="object", sample="{'code': 0,'message': 'success'}")
     */
    public function getGroupFeed2( $id , $since_id = 0 , $filter = 'all' )
    {
        $group_id = intval($id);
        $current_uid = lianmi_uid(); // 0 if guest, >0 if token provided
        $since_id = intval($since_id);

        $groupinfo = db()->getData("SELECT * FROM `group` WHERE `id` = :id LIMIT 1", [':id' => $group_id])->toLine();
        if (!$groupinfo) {
            return lianmi_throw('INPUT', '栏目不存在');
        }

        // Guest Permission: Deny access to private groups that require membership check if user is a pure guest
        if ($groupinfo['is_private'] == 1 && $groupinfo['is_need_check'] == 1 && $current_uid == 0) {
            return lianmi_throw('AUTH', '该栏目内容受限，请登入后查看');
        }

        $member_info = null;
        if ($current_uid > 0) {
            $member_info = db()->getData("SELECT * FROM `group_member` WHERE `uid` = :uid AND `group_id` = :group_id LIMIT 1", [':uid' => $current_uid, ':group_id' => $group_id])->toLine();
        }

        // 1. Fetch Top Feed
        $topfeed_data_final = false;
        if (isset($groupinfo['top_feed_id']) && intval($groupinfo['top_feed_id']) > 0) {
            $top_feed_id = intval($groupinfo['top_feed_id']);
            $topfeed_query_params = [':top_feed_id' => $top_feed_id, ':current_group_id' => $group_id];
            $topfeed_sql = "SELECT *, uid as user, forward_group_id as group FROM feed WHERE id = :top_feed_id AND forward_group_id = :current_group_id AND is_delete = 0 AND status = 'published' LIMIT 1";

            $fetched_topfeed = get_line($topfeed_sql, $topfeed_query_params);

            if ($fetched_topfeed) {
                if ($fetched_topfeed['is_paid'] == 1) { // Paid content
                    if ($current_uid > 0 && $member_info && ($member_info['is_vip'] == 1 || $member_info['is_author'] == 1)) {
                        // Logged-in VIP/Author can see
                    } else {
                        $fetched_topfeed = null; // Guest or non-VIP/Author cannot see paid top feed
                    }
                }
                if ($fetched_topfeed) {
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
            $pinned_feeds_data = array_filter($pinned_feeds_data, function($feed_item) use ($current_uid, $member_info) {
                if ($feed_item['is_paid'] == 1) {
                    if ($current_uid > 0 && $member_info && ($member_info['is_vip'] == 1 || $member_info['is_author'] == 1)) {
                        return true; // Logged-in VIP/Author
                    }
                    return false; // Guest or non-VIP/Author: exclude paid
                }
                return true; // Not paid, include
            });
            $pinned_feeds_data = array_values($pinned_feeds_data);

            $pinned_feeds_data = extend_field($pinned_feeds_data, 'user', 'user');
            $pinned_feeds_data = extend_field($pinned_feeds_data, 'group', 'group');

            if ($topfeed_data_final && isset($topfeed_data_final['id'])) {
                $pinned_feeds_data = array_filter($pinned_feeds_data, function($pf) use ($topfeed_data_final) {
                    return $pf['id'] != $topfeed_data_final['id'];
                });
                $pinned_feeds_data = array_values($pinned_feeds_data);
            }
        } else {
            $pinned_feeds_data = [];
        }

        // 3. Prepare Excluded IDs
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

        // 4. Construct exclude_sql_part
        $exclude_sql_part = "";
        if (!empty($exclude_ids)) {
            $safe_exclude_ids = array_map('intval', $exclude_ids);
            $exclude_sql_part = " AND id NOT IN (" . implode(',', $safe_exclude_ids) . ") ";
        }

        // 5. Fetch Regular Feeds
        $regular_feeds_filter_conditions_list = []; // Renamed to avoid conflict
        $regular_feeds_params = [':forward_group_id' => $group_id];
        
        if ($filter == 'paid') $regular_feeds_filter_conditions_list[] = "`is_paid` = 1"; // This might be restricted by general paid visibility below
        if ($filter == 'media') $regular_feeds_filter_conditions_list[] = "`images` != ''";

        // Visibility for paid regular feeds
        if ($current_uid > 0 && $member_info && ($member_info['is_vip'] == 1 || $member_info['is_author'] == 1)) {
            // Logged-in VIP/Author can see all (paid and non-paid) subject to other filters
        } else {
            // Guest or non-VIP/Author: can only see non-paid
            $regular_feeds_filter_conditions_list[] = "`is_paid` = 0";
        }
        
        if ($since_id > 0) {
            $regular_feeds_filter_conditions_list[] = "`id` < :since_id";
            $regular_feeds_params[':since_id'] = $since_id;
        }
        
        $regular_feeds_where_clause = "WHERE `is_delete` != 1 AND `forward_group_id` = :forward_group_id AND `status` = 'published' AND `is_pinned_in_group` = 0 ";
        if (!empty($regular_feeds_filter_conditions_list)) {
            $regular_feeds_where_clause .= " AND " . join(" AND ", $regular_feeds_filter_conditions_list);
        }
        $regular_feeds_where_clause .= $exclude_sql_part;
        
        $limit_val = intval(c('feeds_per_page'));
        $regular_feeds_sql = "SELECT *, `uid` as `user`, `forward_group_id` as `group` FROM `feed` {$regular_feeds_where_clause} ORDER BY `id` DESC LIMIT {$limit_val}";

        $regular_feeds_data = db()->getData( $regular_feeds_sql, $regular_feeds_params )->toArray();
        $regular_feeds_data = extend_field( $regular_feeds_data , 'user' , 'user' );
        $regular_feeds_data = extend_field( $regular_feeds_data , 'group' , 'group' );
        
        // 6. Calculate Pagination for Regular Feeds
        $maxid_for_regular = null; $minid_for_regular = null;
        if( is_array( $regular_feeds_data ) && count( $regular_feeds_data ) > 0  )
        {
            $maxid_for_regular = $minid_for_regular = $regular_feeds_data[0]['id'];
            foreach( $regular_feeds_data as $item )
            {
                if( $item['id'] > $maxid_for_regular ) $maxid_for_regular = $item['id'];
                if( $item['id'] < $minid_for_regular ) $minid_for_regular = $item['id'];
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
     * Search feeds by query string
     * @ApiDescription(section="Feed", description="Search feed content.")
     * @ApiLazyRoute(uri="/feed/search",method="GET")
     * @ApiParams(name="q", type="string", nullable=false, description="Search query", check="check_not_empty")
     * @ApiParams(name="page", type="int", nullable=true, description="Page number (1-based)", check="check_uint")
     * @ApiReturn(type="array", sample="[{'id':123, 'text':'...'},...]")
     */
    public function searchFeeds($q, $page = 1)
    {
        $limit = intval(c('feeds_per_page')) ?: 20; // Use configured feeds_per_page or default to 20
        $page = intval($page);
        if ($page < 1) {
            $page = 1;
        }
        $offset = ($page - 1) * $limit;
        
        // Prepare search term for LIKE query
        $search_term = "%" . $q . "%";

        // Only search non-forwarded feeds, and non-deleted feeds.
        // Paid content visibility: show only public (is_paid=0) or if user is logged in AND is owner.
        // Since this is GuestApiController, we assume user is not logged in or their identity isn't fully trusted for paid content here.
        // A more advanced search might allow logged-in users to see their own paid content in results.
        // For simplicity in Guest context, we'll filter for public feeds or user's own if logged in.
        
        $sql_conditions = "`is_delete` = 0 AND `is_forward` = 0 AND `text` LIKE :query";
        $params = [':query' => $search_term];

        // Handle visibility of paid content:
        // If user is logged in, they can see their own paid content. Otherwise, only public content.
        $current_uid = lianmi_uid();
        if ($current_uid > 0) {
            $sql_conditions .= " AND (`is_paid` = 0 OR (`is_paid` = 1 AND `uid` = :current_uid))";
            $params[':current_uid'] = $current_uid;
        } else {
            $sql_conditions .= " AND `is_paid` = 0";
        }

        // Count total results for pagination (optional, can be intensive)
        // $count_sql = "SELECT COUNT(*) FROM `feed` WHERE {$sql_conditions}";
        // $total_results = get_var($count_sql, $params); 
        // For now, not implementing total count for simplicity.

        $sql = "SELECT *, `uid` as `user`, `group_id` as `group` FROM `feed` WHERE {$sql_conditions} ORDER BY `id` DESC LIMIT :limit OFFSET :offset";
        
        // PDO does not bind LIMIT/OFFSET directly as integers, they must be part of the string.
        // Ensure they are integers.
        $sql = str_replace(':limit', $limit, $sql);
        $sql = str_replace(':offset', $offset, $sql);
        
        $feeds = get_data($sql, $params);

        if ($feeds) {
            $feeds = extend_field($feeds, 'user', 'user');
            // For original posts (is_forward=0), group_id is the original group.
            // If group_id is 0, it means it's a user's personal feed post not to a specific group.
            // We only extend 'group' if group_id is valid.
            $feeds_with_valid_group = [];
            foreach($feeds as $feed_item) {
                if (isset($feed_item['group']) && intval($feed_item['group']) > 0) {
                    $feeds_with_valid_group[] = $feed_item;
                }
            }
            if(!empty($feeds_with_valid_group)){
                 $enriched_groups = extend_field($feeds_with_valid_group, 'group', 'group');
                 // Merge back - this is a bit tricky as extend_field modifies a copy
                 // A more robust way would be to map results.
                 // For now, let's assume extend_field correctly enriches the passed array items by reference or returns a fully enriched list.
                 // Re-iterate and map results if extend_field doesn't modify by reference effectively for partial lists.
                 // A safer merge:
                 $enriched_feeds_map = [];
                 foreach($enriched_groups as $eg_item) $enriched_feeds_map[$eg_item['id']] = $eg_item;

                 foreach($feeds as $idx => $original_feed_item) {
                     if(isset($enriched_feeds_map[$original_feed_item['id']])) {
                         $feeds[$idx] = $enriched_feeds_map[$original_feed_item['id']];
                     }
                 }
            }
        } else {
            $feeds = [];
        }

        // Consider returning pagination info if $total_results was calculated
        // return send_result(['feeds' => $feeds, 'page' => $page, 'limit' => $limit, 'total_results' => $total_results]);
        return send_result($feeds);
    }
}