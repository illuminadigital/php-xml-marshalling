<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license. For more information, see
 * <http://www.doctrine-project.org>.
 */

namespace Doctrine\OXM\Mapping;

use ReflectionException;
use Doctrine\OXM\OXMException;
use Doctrine\OXM\Configuration;
use Doctrine\Common\Util\Debug;
use Doctrine\OXM\Events;
use Doctrine\Common\Cache\Cache;
use Doctrine\Common\EventManager;
use Doctrine\Common\Persistence\Mapping\ClassMetadata as BaseClassMetadata;
use Doctrine\Common\Persistence\Mapping\ReflectionService;
use Doctrine\Common\Persistence\Mapping\AbstractClassMetadataFactory;
use Doctrine\OXM\Types\Type;

/**
 * The ClassMetadataFactory is used to create Mapping objects that contain all the
 * metadata mapping informations of a class which describes how a class should be mapped
 * to a xml node.
 *
 * @license http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @link    www.doctrine-project.org
 * @since   2.0
 * @version $Revision$
 * @author  Richard Fullmer <richard.fullmer@opensoftdev.com>
 */
class ClassMetadataFactory extends AbstractClassMetadataFactory
{
    /**
     * Salt used by specific Object Manager implementation.
     *
     * @var string
     */
    protected $cacheSalt = "\$XMLCLASSMETADATA";
    
    /**
     * @var \Doctrine\OXM\Configuration
     */
    private $configuration;
    
    /**
     * @var \Doctrine\OXM\Mapping\Driver\Driver
     */
    private $driver;

    /**
     * @var \Doctrine\Common\EventManager
     */
    private $evm;

    /**
     * Keys are mapped xml node names
     *
     * @var array
     */
    private $xmlToClassMap = array();

    /**
     * Keys are mapped xml wrapper names
     *
     * @var array
     */
    private $wrapperXmlToClassMap = array();
    
    /**
     * Keys are the base class name
     * 
     * @var array
     */
    private $alternativeClassMap = array();
    
    private $allLoaded = FALSE;

    /**
     * @param Configuration $configuration
     * @param EventManager|null $evm
     * @return null
     */
    public function __construct(Configuration $configuration, EventManager $evm = null)
    {
        $this->configuration = $configuration;
        $this->evm = $evm;
        
        $this->setCacheDriver($this->configuration->getMetadataCacheImpl()); // Private property of parent class
    }

    /**
     * Preloads all metadata and returns an array of all known mapped node types
     *
     * @return array
     */
    public function getAllXmlNodes()
    {
        if (empty($this->xmlToClassMap)) {
            if (!$this->initialized) {
                $this->initialize();
            }
        
            // Load all metadata
            $this->getAllMetadata();
        }
        
        return $this->xmlToClassMap;
    }

    /**
     * Preloads all metadata and returns an array of all known wrapper node types
     *
     * @return array
     */
    public function getAllWrapperXmlNodes()
    {
        if (empty($this->wrapperXmlToClassMap)) {
            if (!$this->initialized) {
                 $this->initialize();
            }
        
            // Load all metadata
            $this->getAllMetadata();
        }
        
        return $this->wrapperXmlToClassMap;
    }
    
    /**
     * Preloads all metadata and returns an array of all known alternative classes
     * 
     * @return array
     */
    public function getAllAlternativeClasses()
    {
        if (empty($this->alternativeClassMap)) {
    	    if (!$this->initialized) {
    		    $this->initialize();
        	}
    	
        	// Load all metadata
    	    $this->getAllMetadata();
        }
        
    	return $this->alternativeClassMap;
    }
    
    public function getAllMaps() {
        if (!$this->initialized) {
            $this->initialize();
        }
         
        // Load all metadata
        $this->getAllMetadata();

        return array(
            $this->xmlToClassMap,
            $this->wrapperXmlToClassMap,
            $this->alternativeClassMap,
        );
    }
    
    public function getAllMetadata() {
        static $mapKey = 'maps\$XMLCLASSMAPS';
        
        if ($this->allLoaded) {
            return;
        }
        
        $mapData = $this->getCacheDriver()->fetch($mapKey);
        
        if ( ! empty($mapData) ) {
            $this->xmlToClassMap = $mapData['xmlToClassMap'];
            $this->wrapperXmlToClassMap = $mapData['wrapperXmlToClassMap'];
            $this->alternativeClassMap = $mapData['alternativeClassMap'];
            
            $result = TRUE;
        } else {
            $result = parent::getAllMetadata();
        
            $mapData = $this->getCacheDriver()->save($mapKey, array(
                'xmlToClassMap' => $this->xmlToClassMap,
                'wrapperXmlToClassMap' => $this->wrapperXmlToClassMap,
                'alternativeClassMap' => $this->alternativeClassMap,
            ));
        }
        
        $this->allLoaded = TRUE;
        
        return $result;
    }

    /**
     * Lazy initialization of this stuff, especially the metadata driver,
     * since these are not needed at all when a metadata cache is active.
     */
    protected function initialize()
    {
        $this->driver = $this->configuration->getMetadataDriverImpl();

        if (null === $this->evm) {
            $this->evm = new EventManager();
        }
        
        $this->initialized = true;
    }

    /**
     * {@inheritDoc}
     */
    protected function getFqcnFromAlias($namespaceAlias, $simpleClassName)
    {
        return $this->configuration->getEntityNamespace($namespaceAlias) . '\\' . $simpleClassName;
    }
    
    /**
     * {@inheritDoc}
     */
    protected function getDriver()
    {
        return $this->driver;
    }

    /**
     * {@inheritDoc}
     */
    protected function wakeupReflection(BaseClassMetadata $class, ReflectionService $reflService)
    {
        /* @var $class ClassMetadata */
        $class->wakeupReflection($reflService);
        $this->completeMappingTypeValidation($class->getName(), $class);

        $xmlNamespace = empty($class->xmlNamespaces) ? '' : $class->xmlNamespaces[0]['url'];
        
        foreach ($class->xmlNamespaces as $namespaceData) {
            if (empty($namespaceData['prefix'])) {
                $xmlNamespace = $namespaceData['url'];
            }
        }
        
        $xmlName = $class->getXmlName();

        if (!empty($class->parent)) {
            $parent = $this->getMetadataFor($class->parent);
            if ( ! empty($parent) ) {
                $this->alternativeClassMap[$parent->getName()][$xmlNamespace] = $class->getName();
            }
        }
    
        if ( ! $class->isMappedSuperclass) {
            $this->xmlToClassMap[$xmlName][$xmlNamespace] = $class->getName();
        }
    
        if ( $this->isEntity($class)) {
            foreach ($class->getFieldMappings() as $fieldMapping)
            {
                if ( ! empty($fieldMapping['wrapper'] ) )
                {
                    $wrapperXmlName = $fieldMapping['wrapper'];
                    
                    $types = Type::getTypesMap();
                    
                    $type = (string) $fieldMapping['type'];
                    
                    if ( isset($types[$type])) {
                    	$this->wrapperXmlToClassMap[$wrapperXmlName][$class->getXmlName()] = NULL;
                    }
                    else
                    {
                    	$this->wrapperXmlToClassMap[$wrapperXmlName][$class->getXmlName()] = $fieldMapping['type'];                    	
                    }
                }
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    protected function initializeReflection(BaseClassMetadata $class, ReflectionService $reflService)
    {
        /* @var $class ClassMetadata */
        $class->initializeReflection($reflService);
    }

    /**
     * {@inheritDoc}
     */
    protected function isEntity(BaseClassMetadata $class)
    {
        /* @var $class ClassMetadata */
        return isset($class->isMappedSuperclass) && $class->isMappedSuperclass === false;
    }

    /**
     * Loads the metadata of the class in question and all it's ancestors whose metadata
     * is still not loaded.
     * 
     * We can't use the version from AbstractClassMetadataFactory as that assumes the abstract classes should
     * not be in the list. However, the test suite says that you should be able to use the abstract classes
     * as markers, replacing them with one of the concrete implementations. That means the abstract classes 
     * must show in the list of parents.
     *
     * @param string $name The name of the class for which the metadata should get loaded.
     *
     * @return array
     */
    protected function loadMetadata($name)
    {
    	if ( ! $this->initialized) {
    		$this->initialize();
    	}
    
    	$loaded = array();
    
    	$parentClasses = $this->getParentClasses($name);
    	$parentClasses[] = $name;
    	// Move down the hierarchy of parent classes, starting from the topmost class
    	$parent = null;
    	$rootEntityFound = false;
    	$visited = array();
    	$reflService = $this->getReflectionService();
    	foreach ($parentClasses as $className) {
    		if ($this->hasMetadataFor($className)) {
    			$parent = $this->getMetadataFor($className);
    			if ($this->isEntity($parent)) {
    				$rootEntityFound = true;
    			}
    			array_unshift($visited, $className);
    			continue;
    		}
    		
    		$class = $this->newClassMetadataInstance($className);
    		$this->initializeReflection($class, $reflService);
    
    		$this->doLoadMetadata($class, $parent, $rootEntityFound, $visited);
    
    		$this->setMetadataFor($className, $class);
    		
    		$parent = $class;
    
    		if ($this->isEntity($class)) {
    			$rootEntityFound = true;
    		}
    		array_unshift($visited, $className);
    		
    		$this->wakeupReflection($class, $reflService);
    
    		$loaded[] = $className;
    	}
    	return $loaded;
    }
    
    
    /**
     * {@inheritDoc}
     */
    protected function doLoadMetadata($class, $parent, $rootEntityFound, array $nonSuperclassParents)
    {
        /* @var $class \Doctrine\OXM\Mapping\ClassMetadata */
        /* @var $parent \Doctrine\OXM\Mapping\ClassMetadata */
        if ($parent) {
            $class->setIdGeneratorType($parent->generatorType);
            $this->addInheritedFields($class, $parent);
    
            $class->setXmlNamespaces($parent->xmlNamespaces);
            $class->setIdentifier($parent->identifier);
            $class->setLifecycleCallbacks($parent->lifecycleCallbacks);
            $class->setChangeTrackingPolicy($parent->changeTrackingPolicy);
            $class->parent = $parent->getName();
        }
    
        // Invoke driver
        try {
            $this->driver->loadMetadataForClass($class->getName(), $class);
        } catch (ReflectionException $e) {
            throw MappingException::reflectionFailure($class->getName(), $e);
        }

        $xmlNamespace = empty($class->xmlNamespaces) ? '' : $class->xmlNamespaces[0]['url'];
        
        foreach ($class->xmlNamespaces as $namespaceData) {
        	if (empty($namespaceData['prefix'])) {
        		$xmlNamespace = $namespaceData['url'];
        	}
        }
        
        $xmlName = $class->getXmlName();

        // Ignore duplicate binding issues -- at least for now
        /*
        if ( $this->isEntity($class) && array_key_exists($xmlName, $this->xmlToClassMap) && array_key_exists($xmlNamespace, $this->xmlToClassMap[$xmlName])) {
			if ($this->xmlToClassMap[$xmlName][$xmlNamespace] == $class->getName() || $this->xmlToClassMap[$xmlName][$xmlNamespace] == '\\' . $class->getName()) {
				// Ignore
	        } else {
        		throw MappingException::duplicateXmlNameBinding($class->getName(), $class->getXmlName());
	        }
        }
        */
        
        // The previous test should be sufficent for us to just assume that the namespace/alternative is fine
        if ( ! empty($parent) ) {
        	$this->alternativeClassMap[$parent->getName()][$xmlNamespace] = $class->getName();
        }
        
        if ( ! $class->isMappedSuperclass) {
        	$this->xmlToClassMap[$xmlName][$xmlNamespace] = $class->getName();
        }
    
        if ($parent && ! $parent->isMappedSuperclass) {
            if ($parent->generatorType) {
                $class->setIdGeneratorType($parent->generatorType);
            }
            if ($parent->idGenerator) {
                $class->setIdGenerator($parent->idGenerator);
            }
        } else {
            $this->completeIdGeneratorMapping($class);
        }
    
        $class->setParentClasses($nonSuperclassParents);
    
        // Todo - ensure that root elements have an ID mapped
    
        if ($this->evm->hasListeners(Events::loadClassMetadata)) {
            $eventArgs = new LoadClassMetadataEventArgs($class, $this);
            $this->evm->dispatchEvent(Events::loadClassMetadata, $eventArgs);
        }
    }

    /**
     * {@inheritDoc}
     */
    protected function newClassMetadataInstance($className)
    {
        return new ClassMetadata($className);
    }
    
    /**
     * Complete and validate type mappings
     *
     * @param string $className
     * @param ClassMetadataInfo $class
     */
    private function completeMappingTypeValidation($className, ClassMetadataInfo $class)
    {
        foreach ($class->fieldMappings as $fieldName => $mapping) {
            if (Type::hasType($mapping['type'])) {
                continue;
            }

            // Support type as a mapped class?
            if (!$this->hasMetadataFor($mapping['type']) && !$this->getMetadataFor($mapping['type'])) {
                throw MappingException::fieldTypeNotFound($className, $fieldName, $mapping['type']);
            }

            // Mapped classes must have binding node type XML_ELEMENT
            if ($mapping['node'] !== ClassMetadataInfo::XML_ELEMENT) {
                throw MappingException::customTypeWithoutNodeElement($className, $fieldName);
            }
        }
    }

    /**
     * Adds inherited fields to the subclass mapping.
     *
     * @param ClassMetadata $subClass
     * @param ClassMetadata $parentClass
     */
    private function addInheritedFields(ClassMetadata $subClass, ClassMetadata $parentClass)
    {
        foreach ($parentClass->fieldMappings as $fieldName => $mapping) {
            if ( ! isset($mapping['inherited']) && ! $parentClass->isMappedSuperclass) {
                $mapping['inherited'] = $parentClass->name;
            }
            if ( ! isset($mapping['declared'])) {
                $mapping['declared'] = $parentClass->name;
            }
            $subClass->addInheritedFieldMapping($mapping);
        }
        foreach ($parentClass->reflFields as $name => $field) {
            $subClass->reflFields[$name] = $field;
        }
    }

    /**
     * Completes the ID generator mapping. If "auto" is specified we choose the generator
     * most appropriate.
     *
     * @param Doctrine\OXM\Mapping\ClassMetadataInfo $class
     */
    private function completeIdGeneratorMapping(ClassMetadataInfo $class)
    {
        $idGenType = $class->generatorType;
        if ($idGenType == ClassMetadata::GENERATOR_TYPE_AUTO) {
            $class->setIdGeneratorType(ClassMetadataInfo::GENERATOR_TYPE_NONE);
        }

        // Create & assign an appropriate ID generator instance
        switch ($class->generatorType) {
            case ClassMetadataInfo::GENERATOR_TYPE_INCREMENT:
                throw new OXMException("Increment generator type not implemented yet");
                break;
            case ClassMetadataInfo::GENERATOR_TYPE_NONE:
                $class->setIdGenerator(new \Doctrine\OXM\Id\AssignedGenerator());
                break;
            case ClassMetadataInfo::GENERATOR_TYPE_UUID:
                $class->setIdGenerator(new \Doctrine\OXM\Id\UuidGenerator());
                break;
            default:
                throw new OXMException("Unknown generator type: " . $class->generatorType);
        }
    }
    
    /**
     * Looks for an alternative implementation class (eg a child class) based on the namespace
     * 
     * @param string $className The base class to start from
     * @param string $namespace The namespace currently being used
     * @param bool $defaultToBase Whether to use the supplied base if nothing is found 
     * 
     * @return string
     */
    public function getAlternativeClassForNamespace($className, $namespace, $defaultToBase = TRUE)
    {
    	if (substr($className, 0, 1) == '\\') {
    		$className = substr($className, 1);
    	}
    	
    	if ( ! empty($this->alternativeClassMap[$className]) && ! empty($this->alternativeClassMap[$className][$namespace])) {
    		return $this->alternativeClassMap[$className][$namespace];
    	}
    	
    	if ($defaultToBase) {
        	return $className;
    	}
    	// else
    	return NULL;
    }
    
    public function getAlternativeClassForName($className, $name, $defaultToBase = TRUE)
    {
        if (substr($className, 0, 1) == '\\') {
            $className = substr($className, 1);
        }

        if ( ! empty($this->alternativeClassMap[$className]) )
        {
            foreach ($this->alternativeClassMap[$className] as $namespace => $class)
            {
                $classNameElements = explode('\\', $class);
                $classLocalName = array_pop($classNameElements);
                
                if ($name == $classLocalName || $name == strtolower($classLocalName) 
                        || ucfirst($name) == $classLocalName 
                        || $name == preg_replace('/^-/', '', preg_replace('/[A-Z]/e', '"-" . strtolower("$1")', $classLocalName)))
                {
                    return $class;
                }
            }
        }
        
        if ($defaultToBase)
        {
            return $className;
        }
        // else
        return FALSE;
    }
}
