<?php

namespace Documents;

/**
 * @XMLEntity(xml="section")
 */
class Section
{
    /**
     * @XmlText(type="string", required=true)
     */
    public $heading;
    
    /**
     * @XmlText(type="string")
     */
    public $content;
    
    public function __construct($heading, $content)
    {
        $this->heading = $heading;
        $this->content = $content;
    }
    
    public function setHeading($heading)
    {
        $this->heading = $heading;
        return $this;
    }
    
    public function getHeading()
    {
        return $this->heading;
    }
    
    public function setContent($content)
    {
        $this->content = $content;
        return $this;
    }
    
    public function getContent()
    {
        return $this->content;
    }
}