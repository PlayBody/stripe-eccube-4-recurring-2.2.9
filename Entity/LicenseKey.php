<?php

namespace Plugin\StripeRec\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * License
 *
 * @ORM\Table(name="plg_stripe_rec_licensekey")
 * @ORM\Entity(repositoryClass="Plugin\StripeRec\Repository\LicenseRepository")
 */
class LicenseKey
{
    /**
     * @var int
     *
     * @ORM\Column(name="id", type="integer", options={"unsigned":true})
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     */
    private $id;

    /**
     * @var string
     * 
     * @ORM\Column(name="email", type="string", length=255)
     */
    private $email;

    /**
     * @var string
     *
     * @ORM\Column(name="license_key", type="string", length=255)
     */
    private $license_key;

    /**
     * @var int
     * 
     * @ORM\Column(name="key_type", type="integer", options={"unsigned":true})
     */
    private $key_type;

    /**
     * @return string
     * 
     * @ORM\Column(name="instance", type="string", length=255)
     */
    private $instance;
    

    public function getId()
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getEmail()
    {
        return $this->email;
    }
    /**
     * @param string $email
     * 
     * @return $this;
     */
    public function setEmail($email)
    {
        $this->email = $email;

        return $this;
    }
    /**
     * @return string
     */
    public function getLicenseKey()
    {
        return $this->license_key;
    }

    /**
     * @param string $license_key
     *
     * @return $this;
     */
    public function setLicenseKey($license_key)
    {
        $this->license_key = $license_key;

        return $this;
    }

    /**
     * @return int
     */
    public function getKeyType()
    {
        return $this->key_type;
    }

    /**
     * @param int $key_type
     * 
     * @return $this;
     */
    public function setKeyType($key_type)
    {
        $this->key_type = $key_type;

        return $this;
    }
    /**
     * @return string
     */
    public function getInstance()
    {
        return $this->instance;
    }

    /**
     * @param string $instance
     * 
     * @return $this;
     */
    public function setInstance($instance)
    {
        $this->instance = $instance;

        return $this;
    }    
}
