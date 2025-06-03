<?php
use Phinx\Migration\AbstractMigration;

class AddStatusToFeedTable extends AbstractMigration
{
    public function change()
    {
        $table = $this->table('feed');
        $table->addColumn('status', 'string', [
                  'limit' => 20,
                  'default' => 'published',
                  'after' => 'post_type', // Or another suitable column
                  'comment' => 'Status of the feed: published, draft, scheduled, etc.'
              ])
              ->addIndex(['status']) // Index for querying by status
              ->update();
    }
}
?>
