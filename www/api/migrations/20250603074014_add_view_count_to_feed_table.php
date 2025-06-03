<?php
use Phinx\Migration\AbstractMigration;

class AddViewCountToFeedTable extends AbstractMigration
{
    public function change()
    {
        $table = $this->table('feed');
        $table->addColumn('view_count', 'integer', [
                  'signed' => false, // Assuming views can't be negative
                  'default' => 0,
                  'after' => 'up_count', // Or another suitable column like scheduled_at
                  'comment' => 'Number of times the feed has been viewed.'
              ])
              ->addIndex(['view_count']) // Index for sorting by views, if needed later
              ->update();
    }
}
?>
