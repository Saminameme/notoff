<?php
use Phinx\Migration\AbstractMigration;

class AddScheduledAtToFeedTable extends AbstractMigration
{
    public function change()
    {
        $table = $this->table('feed');
        $table->addColumn('scheduled_at', 'datetime', [
                  'null' => true, // Important: Allow NULL for non-scheduled posts
                  'after' => 'status', // Or another suitable column
                  'comment' => 'Timestamp for when a scheduled post should be published.'
              ])
              ->addIndex(['scheduled_at']) // Index for querying by scheduled_at (for the cron job)
              ->update();
    }
}
?>
