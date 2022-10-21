<?php declare(strict_types=1);

namespace Plugin\StripeRec\DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20210412185434 extends AbstractMigration
{
    public function up(Schema $schema) : void
    {
        // this up() migration is auto-generated, please modify it to your needs
        if (!$schema->hasTable('plg_stripe_rec_csv')) {
            $this->addSql('CREATE TABLE plg_stripe_rec_csv (id INT UNSIGNED AUTO_INCREMENT NOT NULL, _type VARCHAR(255) DEFAULT NULL, _entity VARCHAR(255) DEFAULT NULL, _field VARCHAR(255) DEFAULT NULL, _name VARCHAR(255) DEFAULT NULL, _label VARCHAR(255) DEFAULT NULL, _value VARCHAR(255) DEFAULT NULL, sort_no INT NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci ENGINE = InnoDB;');
        }
    }

    public function down(Schema $schema) : void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $schema->dropTable('plg_stripe_rec_csv');
    }
}
