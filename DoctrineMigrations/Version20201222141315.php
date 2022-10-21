<?php declare(strict_types=1);

namespace Plugin\StripeRec\DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20201222141315 extends AbstractMigration
{
    public function up(Schema $schema) : void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $Table = $schema->getTable('plg_stripe_rec_order');
        if(!$Table->hasColumn('coupon_id')){
            $this->addSql('ALTER TABLE plg_stripe_rec_order ADD coupon_id VARCHAR(255) DEFAULT NULL COLLATE utf8_general_ci');
        }
        if($Table->hasColumn('coupon_discount')){
            $this->addSql('ALTER TABLE plg_stripe_rec_order DROP coupon_discount');
        }
        if(!$Table->hasColumn('coupon_discount_str')){
            $this->addSql('ALTER TABLE plg_stripe_rec_order ADD coupon_discount_str VARCHAR(255) DEFAULT NULL COLLATE utf8_general_ci');
        }
        if(!$Table->hasColumn('coupon_name')){
            $this->addSql('ALTER TABLE plg_stripe_rec_order ADD coupon_name VARCHAR(255) DEFAULT NULL COLLATE utf8_general_ci');
        }
    }

    public function down(Schema $schema) : void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');
    }
}
