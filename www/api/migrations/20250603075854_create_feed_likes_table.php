<?php
use Phinx\Migration\AbstractMigration;

class CreateFeedLikesTable extends AbstractMigration
{
    public function change()
    {
        $table = $this->table('feed_likes');
        $table->addColumn('feed_id', 'integer', ['signed' => false])
              ->addColumn('user_id', 'integer', ['signed' => false])
              ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
              ->addForeignKey('feed_id', 'feed', 'id', ['delete'=> 'CASCADE', 'update'=> 'NO_ACTION'])
              ->addForeignKey('user_id', 'user', 'id', ['delete'=> 'CASCADE', 'update'=> 'NO_ACTION'])
              ->addIndex(['feed_id', 'user_id'], ['unique' => true]) // User can only like a feed once
              ->addIndex(['feed_id']) // For quick lookup of likes for a feed
              ->addIndex(['user_id']) // For quick lookup of feeds liked by a user
              ->create();
    }
}
?>
