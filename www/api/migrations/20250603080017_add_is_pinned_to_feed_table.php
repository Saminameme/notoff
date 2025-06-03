<?php
use Phinx\Migration\AbstractMigration;

class AddIsPinnedToFeedTable extends AbstractMigration
{
    public function change()
    {
        $table = $this->table('feed');
        $table->addColumn('is_pinned_in_group', 'boolean', [ // TINYINT(1) in MySQL
                  'default' => 0,
                  'after' => 'scheduled_at', // Or another suitable column like 'status'
                  'comment' => 'Indicates if the feed is pinned in its forward_group_id context.'
              ])
              // Index might be useful if we often query for pinned posts within a group
              // This index would be most effective if combined with forward_group_id
              // ->addIndex(['forward_group_id', 'is_pinned_in_group'])
              // For now, a simple index on the boolean might be less useful than a combined one,
              // but can be added if individual queries for all pinned items become common.
              // Let's hold off on indexing it for now unless performance dictates.
              ->update();
    }
}
?>
