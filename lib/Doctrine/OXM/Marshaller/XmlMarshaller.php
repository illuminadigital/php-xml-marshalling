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

namespace Doctrine\OXM\Marshaller;

use Doctrine\OXM\Mapping\ClassMetadata;
use Doctrine\OXM\Mapping\ClassMetadataFactory;
use Doctrine\OXM\Mapping\MappingException;
use Doctrine\OXM\Marshaller\Helper\ReaderHelper;
use Doctrine\OXM\Marshaller\Helper\WriterHelper;
use Doctrine\OXM\Types\Type;
use Doctrine\OXM\Events;
    
use XMLReader, XMLWriter;
    
/**
 * A marshaller class which uses Xml Writer and Xml Reader php libraries.
 *
 * Requires --enable-xmlreader and --enable-xmlwriter (default in most PHP
 * installations)
 *
 * @license http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @link    www.doctrine-project.org
 * @since   2.0
 * @version $Revision$
 * @author  Richard Fullmer <richard.fullmer@opensoftdev.com>
 */
class XmlMarshaller implements Marshaller
{

    /**
     * Mapping data for all known XmlEntity classes
     *
     * @var \Doctrine\OXM\Mapping\ClassMetadataFactory
     */
    private $classMetadataFactory;

    /**
     * Support for indentation during marshalling
     *
     * @var int
     */
    private $indent = 4;

    /**
     * Xml Character Encoding
     *
     * @var string
     */
    private $encoding = 'UTF-8';

    /**
     * Xml Schema Version
     *
     * @var string
     */
    private $schemaVersion = '1.0';

    /**
     * @var string
     */
    private $visited = array();
    
    /**
     * Whether unknown elements should be handled (as plain objects)
     * 
     * @var boolean
     */
    private $allowUnknownElements = FALSE;

    /**
     * @param ClassMetadataFactory
     */
    public function __construct(ClassMetadataFactory $classMetadataFactory)
    {
        $this->classMetadataFactory = $classMetadataFactory;

    }

    /**
     * @param Doctrine\OXM\Mapping\ClassMetadataFactory
     */
    public function setClassMetadataFactory(ClassMetadataFactory $classMetadataFactory)
    {
        $this->classMetadataFactory = $classMetadataFactory;
    }

    /**
     * @return Doctrine\OXM\Mapping\ClassMetadataFactory
     */
    public function getClassMetadataFactory()
    {
        return $this->classMetadataFactory;
    }

    /**
     * Set the marshallers output indentation level.  Zero for no indentation.
     *
     * @param int $indent
     */
    public function setIndent($indent)
    {
        $this->indent = (int) $indent;
    }

    /**
     * Return the indentation level.  Zero for no indentation.
     *
     * @return int
     */
    public function getIndent()
    {
        return $this->indent;
    }

    /**
     * @param string $encoding
     * @return void
     * 
     * @todo check for valid encoding from http://www.w3.org/TR/REC-xml/#charencoding
     */
    public function setEncoding($encoding)
    {
        $this->encoding = strtoupper($encoding);
    }

    /**
     * @return string
     */
    public function getEncoding()
    {
        return $this->encoding;
    }

    /**
     * @param string $schemaVersion
     * @return void
     */
    public function setSchemaVersion($schemaVersion)
    {
        $this->schemaVersion = $schemaVersion;
    }

    /**
     * @return string
     */
    public function getSchemaVersion()
    {
        return $this->schemaVersion;
    }
    
    /**
     * @param boolean $allow
     * @return void
     */
    public function setAllowUnknownElements($allow)
    {
        $this->allowUnknownElements = (bool) $allow;
    }

    /**
     * @return boolean
     */
    public function getAllowUnknownElements()
    {
        return $this->allowUnknownElements;
    }

    /**
     * @param string $streamUri
     * @return object
     */
    public function unmarshalFromStream($streamUri)
    {
        $reader = new XMLReader();

        if (!$reader->open($streamUri)) {
            throw MarshallerException::couldNotOpenStream($streamUri);
        }

        // Position at first detected element
        while ($reader->read() && $reader->nodeType !== XMLReader::ELEMENT);

        $mappedObject = $this->doUnmarshal($reader);
        $reader->close();

        return $mappedObject;
    }

    /**
     * @param string $xml
     * @return object
     */
    function unmarshalFromString($xml)
    {
        $xml = trim((string) $xml);

        $reader = new XMLReader();

        if (!$reader->XML($xml)) {
            throw MarshallerException::couldNotReadXml($xml);
        }

        // Position at first detected element
        while ($reader->read() && $reader->nodeType !== XMLReader::ELEMENT);

        $mappedObject = $this->doUnmarshal($reader);
        $reader->close();

        return $mappedObject;
    }

    /**
     *
     * INTERNAL: Performance sensitive method
     *
     * @throws \Doctrine\OXM\Mapping\MappingException
     * @param \XMLReader $cursor
     * @return object
     */
    private function doUnmarshal(XMLReader $cursor, $endElement = NULL, $classMetadata = NULL, $virtualNamespace = NULL)
    {
        /*
        $allMappedXmlNodes = $this->classMetadataFactory->getAllXmlNodes();
        $allMappedWrapperXmlNodes = $this->classMetadataFactory->getAllWrapperXmlNodes();
        */
        
        static $allMappedXmlNodes, $allMappedWrapperXmlNodes, $alternativeClasses;
        
        if (empty($allMappedXmlNodes)) {
            list($allMappedXmlNodes, $allMappedWrapperXmlNodes, $alternativeClasses) = $this->classMetadataFactory->getAllMaps();
        }
        
        if ($cursor->nodeType !== XMLReader::ELEMENT && $cursor->nodeType !== XMLReader::CDATA) {
            throw MarshallerException::invalidMarshallerState($cursor);

        }

        $elementName = $cursor->localName;
        
        if ( ! empty($classMetadata)) {
            $isInWrapper = !empty($endElement);
        } else {
            $isInWrapper = FALSE;
            
            if (!array_key_exists($elementName, $allMappedXmlNodes) && !array_key_exists($elementName, $allMappedWrapperXmlNodes) && !array_key_exists('*', $allMappedXmlNodes)) {
                if ($this->allowUnknownElements) {
                    return $this->doUnmarshalUnknownElement($cursor, $endElement, $classMetadata, $virtualNamespace);
                } else {
                    throw MappingException::invalidMapping($elementName);
                }
	        }
        
	        if (array_key_exists($elementName, $allMappedXmlNodes)) {
	            $classes = $allMappedXmlNodes[$elementName];
	        } else {
	        	$classes = $allMappedXmlNodes['*'];
	        }
	        
            if (count($classes) == 1) {
            	$namespaces = array_keys($classes);
            	$className = $classes[array_shift($namespaces)];
            } else {
                // Should try to work out the correct namespace version here
                
                $attributes = $this->getElementAttributes($cursor);
                $attributes['-name'] = $cursor->localName;
                
                foreach ($classes as $thisClass) {
                    $className = $this->classMetadataFactory->getAlternativeClassForNamespace($thisClass, $virtualNamespace, FALSE);

                    if ( ! $className ) {
                        $className = $this->classMetadataFactory->getAlternativeClassForAttributes($thisClass, $virtualNamespace, $attributes, FALSE);
                    }
                    
                    if ( ! $className ) {
                        break;
                    }
                }
                
                if ( ! $className ) {
                    $namespaces = array_keys($classes);
                	$className = $classes[array_shift($namespaces)];
                	
                	error_log('Scream: too many choices! Selected ${className} for ${elementName}');
                	error_log(print_r($classes, TRUE));
                }
            }
            
		    $classMetadata = $this->classMetadataFactory->getMetadataFor($className);
        }
                    
        $mappedObject = $classMetadata->newInstance();

        // Pre Unmarshal hook -- doesn't make sense on the wrapper
        if ($classMetadata->hasLifecycleCallbacks(Events::preUnmarshal)) {
            $classMetadata->invokeLifecycleCallbacks(Events::preUnmarshal, $mappedObject);
        }

		if (! $isInWrapper && $cursor->hasAttributes) {
            while ($cursor->moveToNextAttribute()) {
                if ($classMetadata->hasXmlField($cursor->name)) {
                    $fieldName = $classMetadata->getFieldName($cursor->name);
                    $fieldMapping = $classMetadata->getFieldMapping($fieldName);
                    $type = Type::getType($fieldMapping['type']);

                    if ($classMetadata->isRequired($fieldName) && $cursor->value === null) {
                        throw MappingException::fieldRequired($classMetadata->name, $fieldName);
                    }

                    if ($classMetadata->isCollection($fieldName) || $isInWrapper) {
                        $convertedValues = array();
                        foreach (explode(" ", $cursor->value) as $value) {
                            $convertedValues[] = $type->convertToPHPValue($value);
                        }
                        $classMetadata->setFieldValue($mappedObject, $fieldName, $convertedValues);
                    } else {
                        $classMetadata->setFieldValue($mappedObject, $fieldName, $type->convertToPHPValue($cursor->value));
                    }

                }
            }
            $cursor->moveToElement();
        }

        if (!$cursor->isEmptyElement) {
            $collectionElements = array();

            while ($cursor->read()) {
                if ($cursor->nodeType === XMLReader::END_ELEMENT && 
                		($cursor->name === $elementName || ($isInWrapper && $cursor->name === $endElement))) {
                    // we're at the original element closing node, bug out
                    break;
                }

                if ($cursor->nodeType !== XMLReader::ELEMENT && $cursor->nodeType !== XMLReader::TEXT && $cursor->nodeType !== XMLReader::CDATA) {
                    // skip insignificant elements
                    continue;
                }

                if ($classMetadata->hasXmlField($cursor->localName) || $classMetadata->hasXmlField('*')) {
                    $fieldName = $classMetadata->getFieldName($cursor->localName);
                    $inAnyMode = FALSE;
                    
                    if ( ! $fieldName ) {
                    	$fieldName = $classMetadata->getFieldName('*');
                    	$inAnyMode = TRUE;
                    }

                    // Check for mapped entity as child, add recursively
                    $fieldMapping = $classMetadata->getFieldMapping($fieldName);

                    if ($this->classMetadataFactory->hasMetadataFor($fieldMapping['type'])) {
                    	$namespace = $cursor->namespaceURI ? $cursor->namespaceURI : $virtualNamespace;
                    	
                    	$childClass = NULL;
                    	
                     	if ( $inAnyMode ) {
                 	        $childClass = $this->classMetadataFactory->getAlternativeClassForNamespace($fieldMapping['type'], $namespace, FALSE);
                     	    
                     	    if ( ! $childClass )
                     	    {
                     	        $childClass = $this->classMetadataFactory->getAlternativeClassForName($fieldMapping['type'], $cursor->localName, FALSE);
                     	    }
                     	    
                     	    if ( ! $childClass )
                     	    {
    							// Check the global mapping
    							if ( ! empty($allMappedXmlNodes[$cursor->localName]) ) {
    								$childClass = $allMappedXmlNodes[$cursor->localName];
    								if (is_array($childClass)) {
    									$childClass = array_shift($childClass);
    								} 
    							}
                     	    }                    		
                     	} 
                     	
                     	if ( empty($childClass)) {
                     	    $attributes = $this->getElementAttributes($cursor);
                     	    $attributes['-name'] = $cursor->localName;
                     	    
                     	    $childClass = $this->classMetadataFactory->getAlternativeClassForAttributes($fieldMapping['type'], $namespace, $attributes, FALSE);

                     	    if ( ! $childClass ) {
                         		$childClass = $this->classMetadataFactory->getAlternativeClassForNamespace($fieldMapping['type'], $namespace);
                     	    }
                     	}
                     	
                     	$childClassMetadata = $this->classMetadataFactory->getMetadataFor($childClass);
                    	 
                        if ($classMetadata->hasFieldWrapping($fieldName) && ! $isInWrapper) {
                            $cursor->moveToElement();
                            $collectionElements[$fieldName] = $this->doUnmarshal($cursor, $fieldName, $classMetadata, $namespace);
                        } elseif ($classMetadata->isCollection($fieldName) || $isInWrapper) {
                            $collectionElements[$fieldName][] = $this->doUnmarshal($cursor, NULL, $childClassMetadata, $namespace);
                        } else {
                            $classMetadata->setFieldValue($mappedObject, $fieldName, $this->doUnmarshal($cursor, NULL, $childClassMetadata, $namespace));
                        }
                    } elseif ($cursor->isEmptyElement) {
                        // Don't move on just yet!
                        $type = Type::getType($fieldMapping['type']);
                        if ($classMetadata->isCollection($fieldName) || $isInWrapper) {
                            $collectionElements[$fieldName][] = $type->convertToPHPValue($cursor->value);
                        } else {
                            $classMetadata->setFieldValue($mappedObject, $fieldName, $type->convertToPHPValue($cursor->value));
                        }
                        // Still don't move on as the loop will
                    } else {
                        // assume text element (dangerous?)
                        $cursor->read();

                        if (!$cursor->isEmptyElement && $cursor->nodeType !== XMLReader::END_ELEMENT) {
                            if ($cursor->nodeType !== XMLReader::TEXT && $cursor->nodeType !== XMLReader::CDATA) {
                                throw MarshallerException::invalidMarshallerState($cursor);
                            }

                            $type = Type::getType($fieldMapping['type']);
                            if ($classMetadata->isCollection($fieldName) || $isInWrapper) {
                                $collectionElements[$fieldName][] = $type->convertToPHPValue($cursor->value);
                            } else {
                                $classMetadata->setFieldValue($mappedObject, $fieldName, $type->convertToPHPValue($cursor->value));
                            }

                            $cursor->read();
                        }
                    }
                } elseif ($cursor->nodeType === XMLReader::TEXT || $cursor->nodeType === XMLReader::CDATA) {
                	foreach ($classMetadata->getFieldNames() as $fieldName) {
                        if (ClassMetadata::XML_VALUE === $classMetadata->getFieldXmlNode($fieldName)) {
                            $fieldMapping = $classMetadata->getFieldMapping($fieldName);
                            $type = Type::getType($fieldMapping['type']);
                            $classMetadata->setFieldValue($mappedObject, $fieldName, $type->convertToPHPValue($cursor->value));
                        }
                    }
                } elseif (array_key_exists($cursor->name, $allMappedXmlNodes) 
                        || (array_key_exists($cursor->name, $allMappedWrapperXmlNodes) && isset($allMappedWrapperXmlNodes[$cursor->name][$elementName])) ) {  // look for inherited child directly
                	if (array_key_exists($cursor->name, $allMappedWrapperXmlNodes) && isset($allMappedWrapperXmlNodes[$cursor->name][$elementName]) )
                	{
                		if ($allMappedWrapperXmlNodes[$cursor->name][$elementName] === NULL) {
                			$childClassMetadata = NULL;
                		} else {
	                		$childClassMetadata = $this->classMetadataFactory->getMetadataFor($allMappedWrapperXmlNodes[$cursor->name][$elementName]);
                		}
                	}
                	elseif (isset($allMappedXmlNodes[$cursor->name]))
                	{
                		if (is_array($allMappedXmlNodes[$cursor->name]))
                		{
                			// FIXME: This is fragile as it doesn't account for the same xml name being used more than once
                			$childClasses = array_values($allMappedXmlNodes[$cursor->name]);
                			$childClass = array_shift($childClasses);
                		}
                		else
                		{
                			$childClass = $allMappedXmlNodes[$cursor->name];
                		}
                		
                		$childClassMetadata = $this->classMetadataFactory->getMetadataFor($childClass);
                	}

                    // todo: ensure this potential child inherits from parent correctly
                    
                    $fieldName = null;
                    $isWrapper = FALSE;
                    
                    foreach ($classMetadata->getFieldMappings() as $fieldMapping) {
                    	if (isset($allMappedXmlNodes[$cursor->name]) && $fieldMapping['type'] == $allMappedXmlNodes[$cursor->name]) {
                    		$fieldName = $fieldMapping['fieldName'];
                    		break;
                    	} elseif (isset($allMappedWrapperXmlNodes[$cursor->name][$elementName]) && $fieldMapping['type'] == $allMappedWrapperXmlNodes[$cursor->name][$elementName]) {
                    		$fieldName = $fieldMapping['fieldName'];
                    		$isWrapper = TRUE;
                    		break;
                    	} else {
                            // Walk parent tree
                    		if ( ! isset($childClassMetadata) ) {
                            	continue;
                            }
                            foreach ($childClassMetadata->getParentClasses() as $parentClass) {
                            	if ($fieldMapping['type'] == $parentClass) {
                                    $fieldName = $fieldMapping['fieldName'];
                                    break 2;
                                }
                            }
                        }
                    }

                    if ($fieldName !== null) {
                    	$namespace = $cursor->namespaceURI ? $cursor->namespaceURI : $virtualNamespace;
                    	
                        if ($isWrapper) {
							$thisFieldMapping = $classMetadata->getFieldMapping($fieldName);
							$childEndElement = $thisFieldMapping['wrapper'];
                            $childElements = $this->doUnmarshal($cursor, $childEndElement, $classMetadata, $namespace);
                        
                            $collectionElements[$fieldName] = $childElements;
                        } elseif ($classMetadata->isCollection($fieldName)) {
                            $collectionElements[$fieldName][] = $this->doUnmarshal($cursor, NULL, NULL, $namespace);
                        } else {
                            $classMetadata->setFieldValue($mappedObject, $fieldName, $this->doUnmarshal($cursor, NULL, NULL, $namespace));
                        }
                    }
                } elseif ($this->allowUnknownElements) {
                    $elementName = $cursor->name;
                    $mappedObject->$elementName = $this->doUnmarshalUnknownElement($cursor, $endElement, $classMetadata, $virtualNamespace);
                }
            }

            if (!empty($collectionElements)) {
                if ( ! $isInWrapper || ! isset($fieldName) ) { 
                    foreach ($collectionElements as $fieldName => $elements) {
                        $classMetadata->setFieldValue($mappedObject, $fieldName, $elements);
                    }
                } else {
                    if ( ! empty($collectionElements[$fieldName]) && (is_array($collectionElements[$fieldName]) && ! empty($collectionElements[$fieldName][0]))) {
                        $mappedObject = $collectionElements[$fieldName];
                    }
                }
            }
        }

        if ( ! $isInWrapper ) {
            // PostUnmarshall hook
            if ($classMetadata->hasLifecycleCallbacks(Events::postUnmarshal)) {
                $classMetadata->invokeLifecycleCallbacks(Events::postUnmarshal, $mappedObject);
            }
        }

        return $mappedObject;
    }
    
    private function getElementAttributes(XMLReader $cursor) {
        if ($cursor->nodeType !== XMLReader::ELEMENT) {
            return FALSE;
        }
        
        $attributes = array();
        
        if ($cursor->hasAttributes) {
            while ($cursor->moveToNextAttribute()) {
                $attributes[$cursor->name] = $cursor->value;
            }
        
            $cursor->moveToElement();
        }
        
        return $attributes;
    }

    private function doUnmarshalUnknownElement(XMLReader $cursor, $endElement = NULL, $classMetadata = NULL, $virtualNamespace = NULL) {
        $mappedObject = new \stdClass();
        
        $mappedObject->_name = $cursor->name;
        
        while ($cursor->moveToNextAttribute()) {
            $mappedObject->_attrs[$cursor->name] = $cursor->value;
        }
        
        while ($cursor->read() && $cursor->nodeType != XMLReader::END_ELEMENT) {
            switch ($cursor->nodeType) {
                case XMLReader::TEXT: 
                case XMLReader::CDATA: 
                    $mappedObject->_contents[] = $cursor->value;
                    
                    break;
                    
                case XMLReader::ELEMENT:
                    $elementName = $cursor->name;
                    $mappedObject->_contents[] = array(
                        'name' => $elementName,
                        'element' => $this->doUnmarshal($cursor, NULL, NULL, $virtualNamespace),
                    );
                    
                    break;
            }
        }
        
        return $mappedObject;
    }
    
    
    /**
     * @param object $mappedObject
     * @return string
     */
    function marshalToString($mappedObject)
    {
        $writer = new WriterHelper($this);
        // clear the internal visited list
        $this->visited = array();

        // Begin marshalling
        $this->doMarshal($mappedObject, $writer);

        return $writer->flush();
    }


    /**
     * @param object $mappedObject
     * @param string $streamUri
     * @return bool|int
     */
    public function marshalToStream($mappedObject, $streamUri)
    {
        $writer = new WriterHelper($this, $streamUri);
        // clear the internal visited list
        $this->visited = array();

        // Begin marshalling
        $this->doMarshal($mappedObject, $writer);

        return $writer->flush();
    }

    /**
     * INTERNAL: Performance sensitive method
     *
     * @throws MarshallerException
     * @param object $mappedObject
     * @param WriterHelper $writer
     * @return void
     */
    private function doMarshal($mappedObject, WriterHelper $writer, $fieldMapping = NULL)
    {
        $className = get_class($mappedObject);
        try {
            $classMetadata = $this->classMetadataFactory->getMetadataFor($className);
        } catch (\Doctrine\OXM\Mapping\MappingException $e) {
            $classMetadata = NULL;
        }

        if ( ! $classMetadata ) {
            if ( ! $this->allowUnknownElements ) {
                throw MarshallerException::mappingNotFoundForClass($className);
            }
        } else {
            // PreMarshall Hook
            if ($classMetadata->hasLifecycleCallbacks(Events::preMarshal)) {
                $classMetadata->invokeLifecycleCallbacks(Events::preMarshal, $mappedObject);
            }
        }

        if (isset($this->visited[spl_object_hash($mappedObject)])) {
            return;
        }

        $this->visited[spl_object_hash($mappedObject)] = true;

        if ( ! $classMetadata ) {
            return $this->doMarshalUnknownElement($mappedObject, $writer, $fieldMapping);
        }
        
        $xmlName = $classMetadata->getXmlName();
        if (isset($fieldMapping['forceName']) && $fieldMapping['forceName'] && isset($fieldMapping['name']) && $fieldMapping['name'] != '*')
        { 
            $xmlName = $fieldMapping['name'];
        }
        $writer->startElement($xmlName);

        $namespaces = $classMetadata->getXmlNamespaces();
        if (!empty($namespaces)) {
            foreach ($namespaces as $namespace) {
                $writer->writeNamespace($namespace['url'], $namespace['prefix']);
            }
        }

        // build ordered field mappings for this class
        $fieldMappings = $classMetadata->getFieldMappings();
        $orderedMap = array();
        if (!empty($fieldMappings)) {
            foreach ($fieldMappings as $fieldMapping) {
                $orderedMap[$fieldMapping['node']][] = $fieldMapping;
            }
        }

        if ($this->allowUnknownElements) {
            $doneFields = array();
        }
        
        // do attributes
        if (array_key_exists(ClassMetadata::XML_ATTRIBUTE, $orderedMap)) {
            foreach ($orderedMap[ClassMetadata::XML_ATTRIBUTE] as $fieldMapping) {

                $fieldName = $fieldMapping['fieldName'];
                $fieldValue = $classMetadata->getFieldValue($mappedObject, $fieldName);

                if ($classMetadata->isRequired($fieldName) && $fieldValue === null) {
                    throw MarshallerException::fieldRequired($className, $fieldName);
                }

                if ($fieldValue !== null || $classMetadata->isNullable($fieldName)) {
                    $this->writeAttribute($writer, $classMetadata, $fieldName, $fieldValue);
                }
                            
                if ($this->allowUnknownElements) {
                    $doneFields[$fieldName] = TRUE;
                }
            }
        }

        // do others
        if ( ! empty($fieldMappings) ) {
            foreach ($fieldMappings as $fieldMapping) {
            	
            	if ($fieldMapping['node'] == ClassMetadata::XML_ATTRIBUTE)
            	{
            		// Already done
            		continue;
            	}

            	$fieldName = $fieldMapping['fieldName'];
                $fieldValue = $classMetadata->getFieldValue($mappedObject, $fieldName);

                if ($classMetadata->isRequired($fieldName) && $fieldValue === null) {
                    throw MarshallerException::fieldRequired($className, $fieldName);
                }

                if ($fieldValue !== null || $classMetadata->isNullable($fieldName)) {
                	if ($fieldMapping['node'] == ClassMetadata::XML_VALUE) {
                		$type = $classMetadata->getTypeOfField($fieldName);
                		$writer->writeValue(Type::getType($type)->convertToXmlValue($fieldValue));
                	} else if ($fieldMapping['node'] == ClassMetadata::XML_TEXT) {
	                    $this->writeText($writer, $classMetadata, $fieldName, $fieldValue);
                	} else {
	                    $this->writeElement($writer, $classMetadata, $fieldName, $fieldValue);
                	}
                }
                
                if ($this->allowUnknownElements) {
                    $doneFields[$fieldName] = TRUE;
                }
            }
        }
        
        // Do anything that still remains
        if ($this->allowUnknownElements) {
            foreach (get_object_vars($mappedObject) as $thisPropertyName => $thisProperty) {
                if (isset($doneFields[$thisPropertyName])) {
                    continue;
                }
                
                $this->doMarshal($thisProperty, $writer);
            }
        }

        // PostMarshal hook
        if ($classMetadata->hasLifecycleCallbacks(Events::postMarshal)) {
            $classMetadata->invokeLifecycleCallbacks(Events::postMarshal, $mappedObject);
        }

        $writer->endElement();
    }
    
    private function doMarshalUnknownElement($mappedObject, WriterHelper $writer, $fieldMapping = NULL) {
        $className = get_class($mappedObject);
        
        if ($className != 'stdClass' || ! isset($mappedObject->_name)) {
            // We can't handle this
            throw MarshallerException::mappingNotFoundForClass($className);
        }
        
        $writer->startElement($mappedObject->_name);
        
        if (isset($mappedObject->_attrs)) {
            foreach ($mappedObject->_attrs as $attrName => $attrValue) {
                $writer->writeAttribute($attrName, $attrValue);
            }
        }

        if (isset($mappedObject->_contents)) {
            foreach ($mappedObject->_contents as $content) {
                if (is_array($content)) {
                    // Some form of element
                    $this->doMarshal($content['element'], $writer);
                } else {
                    $writer->writeValue($content);
                }
            }
        }
        
        $writer->endElement();
    }

    /**
     * @param WriterHelper $writer
     * @param ClassMetadata $classMetadata
     * @param string $fieldName
     * @param mixed $fieldValue
     */
    private function writeAttribute(WriterHelper $writer, ClassMetadata $classMetadata, $fieldName, $fieldValue)
    {
        $name    = $classMetadata->getFieldXmlName($fieldName);
        $type    = $classMetadata->getTypeOfField($fieldName);
        $mapping = $classMetadata->getFieldMapping($fieldName);
        $prefix  = (isset($mapping['prefix']) ? $mapping['prefix'] : null);

        if ($classMetadata->isCollection($fieldName)) {
            $convertedValues = array();
            foreach ($fieldValue as $value) {
                $convertedValues[] = Type::getType($type)->convertToXmlValue($value);
            }

            $writer->writeAttribute($name, implode(" ", $convertedValues), $prefix);
        } else {
            $writer->writeAttribute($name, Type::getType($type)->convertToXmlValue($fieldValue), $prefix);
        }
    }

    /**
     * @param WriterHelper $writer
     * @param ClassMetadata $classMetadata
     * @param string $fieldName
     * @param mixed $fieldValue
     */
    private function writeText(WriterHelper $writer, ClassMetadata $classMetadata, $fieldName, $fieldValue)
    {
        $xmlName = $classMetadata->getFieldXmlName($fieldName);
        $type    = $classMetadata->getTypeOfField($fieldName);
        $mapping = $classMetadata->getFieldMapping($fieldName);
        $prefix  = (isset($mapping['prefix']) ? $mapping['prefix'] : null);

        if ($classMetadata->isCollection($fieldName)) {
            if ($classMetadata->hasFieldWrapping($fieldName)) {
                $writer->startElement($mapping['wrapper'], $prefix);
            }
            foreach ($fieldValue as $value) {
                $writer->writeElement($xmlName, Type::getType($type)->convertToXmlValue($value), $prefix);
            }
            if ($classMetadata->hasFieldWrapping($fieldName)) {
                $writer->endElement();
            }
        } else {
            $writer->writeElement($xmlName, Type::getType($type)->convertToXmlValue($fieldValue), $prefix);
        }
    }

    /**
     * @param WriterHelper $writer
     * @param ClassMetadata $classMetadata
     * @param string $fieldName
     * @param mixed $fieldValue
     */
    private function writeElement(WriterHelper $writer, ClassMetadata $classMetadata, $fieldName,  $fieldValue)
    {
        $fieldType = $classMetadata->getTypeOfField($fieldName);
        $mapping = $classMetadata->getFieldMapping($fieldName);
        $prefix  = (isset($mapping['prefix']) ? $mapping['prefix'] : null);

        if ($this->classMetadataFactory->hasMetadataFor($fieldType)) {
            if ($classMetadata->isCollection($fieldName)) {
                if ($classMetadata->hasFieldWrapping($fieldName)) {
                    $writer->startElement($mapping['wrapper'], $prefix);
                }
                foreach ($fieldValue as $value) {
                    $this->doMarshal($value, $writer, $mapping);
                }
                if ($classMetadata->hasFieldWrapping($fieldName)) {
                    $writer->endElement();
                }
            } else {
                $this->doMarshal($fieldValue, $writer, $mapping);
            }
        } else if ($this->allowUnknownElements) {
            $this->doMarshal($fieldValue, $writer, $mapping);
        }
    }
}
