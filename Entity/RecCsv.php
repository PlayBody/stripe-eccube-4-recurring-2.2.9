<?php

namespace Plugin\StripeRec\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * RecCsv
 *
 * @ORM\Table(name="plg_stripe_rec_csv")
 * @ORM\Entity(repositoryClass="Plugin\StripeRec\Repository\RecCsvRepository")
 */
class RecCsv
{
    /**
     * @var int
     *
     * @ORM\Column(name="id", type="integer", length=11, options={"unsigned":true})
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     */
    private $id;

    /**
     * @var string
     * 
     * @ORM\Column(name="_type", type="string", nullable=true)
     */
    private $type;
    
    /**
     * @var string
     * 
     * @ORM\Column(name="_entity", type="string", nullable=true)
     */
    private $entity;

    /**
     * @var string
     * 
     * @ORM\Column(name="_field", type="string", nullable=true)
     */
    private $field;

    /**
     * @var string
     * 
     * @ORM\Column(name="_name", type="string", nullable=true)
     */
    private $name;

    /**
     * @var string
     * 
     * @ORM\Column(name="_label", type="string", nullable=true)
     */
    private $label;

    /**
     * @var string
     * 
     * @ORM\Column(name="_value", type="string", nullable=true)
     */
    private $value;

    /**
     * @var int
     *
     * @ORM\Column(name="sort_no", type="integer")
     */
    private $sort_no;

    
    public function getId()
    {
        return $this->id;
    }
    public function setId($id) 
    {
        $this->id = $id;
        return $this;
    }

    public function getType()
    {
        return $this->type;
    }
    public function setType($type) 
    {
        $this->type = $type;
        return $this;
    }
    public function getEntity()
    {
        return $this->entity;
    }
    public function setEntity($entity) 
    {
        $this->entity = $entity;
        return $this;
    }
    public function getField()
    {
        return $this->field;
    }
    public function setField($field) 
    {
        $this->field = $field;
        return $this;
    }
    public function getName()
    {
        return $this->name;
    }
    public function setName($name) 
    {
        $this->name = $name;
        return $this;
    }
    public function getLabel()
    {
        return $this->label;
    }
    public function setLabel($label) 
    {
        $this->label = $label;
        return $this;
    }
    public function getValue()
    {
        return $this->value;
    }
    public function setValue($value) 
    {
        $this->value = $value;
        return $this;
    }

    public function getSortNo()
    {
        return $this->sort_no;
    }
    public function setSortNo($sort_no)
    {
        $this->sort_no = $sort_no;
        return $this;
    }
}