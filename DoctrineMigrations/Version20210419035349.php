<?php declare(strict_types=1);

namespace Plugin\StripeRec\DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20210419035349 extends AbstractMigration
{
    public function up(Schema $schema) : void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $Table = $schema->getTable('plg_stripe_rec_order');
        if(!$Table->hasColumn('order_item_id')){
            $this->addSql("ALTER TABLE plg_stripe_rec_order ADD order_item_id VARCHAR(255) DEFAULT NULL;");
        }
    }

    public function down(Schema $schema) : void
    {
        // this down() migration is auto-generated, please modify it to your needs

    }
}
