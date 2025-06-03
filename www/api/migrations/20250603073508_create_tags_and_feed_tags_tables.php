<?php
use Phinx\Migration\AbstractMigration;

class CreateTagsAndFeedTagsTables extends AbstractMigration
{
    public function change()
    {
        // Tags table
        $tagsTable = $this->table('tags');
        $tagsTable->addColumn('name', 'string', ['limit' => 100])
                  ->addColumn('slug', 'string', ['limit' => 100, 'null' => true]) // Optional, can be auto-generated
                  ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                  ->addColumn('updated_at', 'datetime', ['null' => true, 'update' => 'CURRENT_TIMESTAMP']) // Automatically updates on UPDATE
                  ->addIndex(['name'], ['unique' => true])
                  ->addIndex(['slug'], ['unique' => true, 'null' => true]) // Slug should also be unique if used
                  ->create();

        // Feed_tags pivot table (for many-to-many relationship between feed and tags)
        $feedTagsTable = $this->table('feed_tags', ['id' => false, 'primary_key' => ['feed_id', 'tag_id']]);
        $feedTagsTable->addColumn('feed_id', 'integer', ['signed' => false])
                      ->addColumn('tag_id', 'integer', ['signed' => false])
                      ->addForeignKey('feed_id', 'feed', 'id', ['delete'=> 'CASCADE', 'update'=> 'NO_ACTION'])
                      ->addForeignKey('tag_id', 'tags', 'id', ['delete'=> 'CASCADE', 'update'=> 'NO_ACTION'])
                      ->addIndex(['feed_id'])
                      ->addIndex(['tag_id'])
                      ->create();
    }
}
?>
