<?php
use Phinx\Migration\AbstractMigration;

class CreatePollsTables extends AbstractMigration
{
    public function change()
    {
        // Polls table
        $pollsTable = $this->table('polls');
        $pollsTable->addColumn('feed_id', 'integer', ['signed' => false])
                   ->addColumn('question', 'text')
                   ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                   ->addForeignKey('feed_id', 'feed', 'id', ['delete'=> 'CASCADE', 'update'=> 'NO_ACTION'])
                   ->addIndex(['feed_id'], ['unique' => true]) // A feed can only have one poll
                   ->create();

        // Poll_options table
        $pollOptionsTable = $this->table('poll_options');
        $pollOptionsTable->addColumn('poll_id', 'integer', ['signed' => false])
                         ->addColumn('option_text', 'string', ['limit' => 255])
                         // Add order column if option order is important and needs to be preserved
                         // ->addColumn('sort_order', 'integer', ['default' => 0])
                         ->addForeignKey('poll_id', 'polls', 'id', ['delete'=> 'CASCADE', 'update'=> 'NO_ACTION'])
                         ->addIndex(['poll_id'])
                         ->create();

        // Poll_votes table
        $pollVotesTable = $this->table('poll_votes');
        $pollVotesTable->addColumn('poll_id', 'integer', ['signed' => false]) // For easier check if user voted in poll
                        ->addColumn('poll_option_id', 'integer', ['signed' => false])
                        ->addColumn('user_id', 'integer', ['signed' => false])
                        ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                        ->addForeignKey('poll_id', 'polls', 'id', ['delete'=> 'CASCADE', 'update'=> 'NO_ACTION'])
                        ->addForeignKey('poll_option_id', 'poll_options', 'id', ['delete'=> 'CASCADE', 'update'=> 'NO_ACTION'])
                        ->addForeignKey('user_id', 'user', 'id', ['delete'=> 'CASCADE', 'update'=> 'NO_ACTION'])
                        // Unique constraint: a user can only vote once per poll
                        ->addIndex(['poll_id', 'user_id'], ['unique' => true])
                        ->addIndex(['poll_option_id']) // To count votes per option
                        ->addIndex(['user_id'])
                        ->create();
    }
}
?>
