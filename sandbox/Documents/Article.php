<?php 

namespace Documents;

use Doctrine\Common\Collections\ArrayCollection;

/**
 * @XmlRootEntity(xml="article")
 */
class Article
{
    /**
     * @XmlId
     * @XmlField(type="string", node="attribute")
     */
    public $id;
    
    /**
     * @XmlText(type="string", required=true)
     */
    public $name;
    
    /*
     * @EmbedMany(targetDocument="Documents\Section")
     * 
     */
    /**
     * @XmlElement(type="Documents\Section", collection="true", wrapper="sections")
     */
    public $sections;
    
    
    public function __construct()
    {
        $this->sections = new ArrayCollection();
    }
    
    public function setName($name)
    {
        $this->name = $name;
        
        return $this;
    }
    
    public function getName()
    {
        return $this->name;
    }
    
    public function getId()
    {
        return $this->id;
    }
    
    public function setId($id)
    {
        $this->id = $id;

        return $this;
    }
    
    public function getSections()
    {
        return $this->sections;
    }
    
    public function addSection(Section $section)
    {
        $this->sections[] = $section;
    }
    
    public function setSections(array $sections)
    {
        foreach ($sections as $section)
        {
            if ($section instanceof Section)
            {
                $this->sections[] = $section;
            }
        }
    }
    
    public function removeSection(Section $section)
    {
        $this->sections->remove($section);
    }
}