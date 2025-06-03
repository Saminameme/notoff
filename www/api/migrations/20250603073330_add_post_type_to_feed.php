<?php
use Phinx\Migration\AbstractMigration;
class AddPostTypeToFeed extends AbstractMigration
{
    public function change()
    {
        $table = $this->table('feed');
        $table->addColumn('post_type', 'string', ['limit' => 20, 'default' => 'text', 'after' => 'images']) // Or a suitable default like 'image'
              ->update();
    }
}
?>
