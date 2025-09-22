<?php

namespace App\XmlProcessor;

use DOMCharacterData;
use DOMDocument;
use DOMException;
use Exception;
use SimpleXMLElement;
use XMLReader;

/**
 * Simple XML Reader
 *
 * @license Public Domain
 * @author Dmitry Pyatkov(aka dkrnl) <dkrnl@yandex.ru>
 * @url http://github.com/dkrnl/SimpleXMLReader
 */
class SimpleXMLReader
{
	/**
	 * Callbacks
	 *
	 * @var array
	 */
	protected array $callback = array();

	/**
	 * Previous depth
	 *
	 * @var int
	 */
	protected int $prevDepth = 0;


	/**
	 * Stack of the parsed nodes
	 *
	 * @var array
	 */
	protected array $nodesParsed = array();


	/**
	 * Stack of the node types
	 *
	 * @var array
	 */
	protected array $nodesType = array();


	/**
	 * Stack of node position
	 *
	 * @var array
	 */
	protected array $nodesCounter = array();

	/**
	 * Do not remove redundant white space.
	 *
	 * @var bool
	 */
	public bool $preserveWhiteSpace = true;

	private XMLReader|null $xmlReader;

	public function __construct()
	{
		$this->xmlReader = null;
	}

	public function openFile(string $filename, ?string $encoding = null, int $flags = 0): bool
	{
		$this->xmlReader = XMLReader::open($filename, $encoding, $flags);

		if ($this->xmlReader) {
			return true;
		}
		return false;
	}

	public function openAsXml(string $string, ?string $encoding = null, int $flags = 0): bool
	{
		$this->xmlReader = XMLReader::XML($string, $encoding, $flags);

		if ($this->xmlReader) {
			return true;
		}
		return false;
	}

	/**
	 * Add node callback
	 *
	 * @param string $xpath
	 * @param callback $callback
	 * @param integer $nodeType
	 * @return SimpleXMLReader
	 * @throws Exception
	 */
	public function registerCallback(string $xpath, callable $callback, int $nodeType = XMLREADER::ELEMENT): SimpleXMLReader
	{
		if (isset($this->callback[$nodeType][$xpath])) {
			throw new SimpleXmlParserException("Already exists callback '$xpath':$nodeType.");
		}
		if (!is_callable($callback)) {
			throw new SimpleXmlParserException("Not callable callback '$xpath':$nodeType.");
		}
		$this->callback[$nodeType][$xpath] = $callback;
		return $this;
	}

	/**
	 * Remove node callback
	 *
	 * @param string $xpath
	 * @param integer $nodeType
	 * @return SimpleXMLReader
	 * @throws Exception
	 */
	public function unRegisterCallback(string $xpath, int $nodeType = XMLREADER::ELEMENT): SimpleXMLReader
	{
		if (!isset($this->callback[$nodeType][$xpath])) {
			throw new SimpleXmlParserException("Unknown parser callback '$xpath':$nodeType.");
		}
		unset($this->callback[$nodeType][$xpath]);
		return $this;
	}

	/**
	 * Moves cursor to the next node in the document.
	 *
	 * @link http://php.net/manual/en/xmlreader.read.php
	 * @return bool Returns TRUE on success or FALSE on failure.
	 * @throws Exception
	 */
	public function read(): bool
	{
		if (!$this->xmlReader) {
			throw new SimpleXmlDataException("Empty xml: Trying to parse XML date before it has been loaded.");
		}
		$read = $this->xmlReader->read();
		if ($this->xmlReader->depth < $this->prevDepth) {
			if (!isset($this->nodesParsed[$this->xmlReader->depth])) {
				throw new SimpleXmlParserException("Invalid xml: missing items in SimpleXMLReader::\$nodesParsed");
			}
			if (!isset($this->nodesCounter[$this->xmlReader->depth])) {
				throw new SimpleXmlParserException("Invalid xml: missing items in SimpleXMLReader::\$nodesCounter");
			}
			if (!isset($this->nodesType[$this->xmlReader->depth])) {
				throw new SimpleXmlParserException("Invalid xml: missing items in SimpleXMLReader::\$nodesType");
			}
			$this->nodesParsed = array_slice($this->nodesParsed, 0, $this->xmlReader->depth + 1, true);
			$this->nodesCounter = array_slice($this->nodesCounter, 0, $this->xmlReader->depth + 1, true);
			$this->nodesType = array_slice($this->nodesType, 0, $this->xmlReader->depth + 1, true);
		}
		if (isset($this->nodesParsed[$this->xmlReader->depth]) && $this->xmlReader->localName === $this->nodesParsed[$this->xmlReader->depth] && $this->xmlReader->nodeType === $this->nodesType[$this->xmlReader->depth]) {
			++$this->nodesCounter[$this->xmlReader->depth];
		} else {
			$this->nodesParsed[$this->xmlReader->depth] = $this->xmlReader->localName;
			$this->nodesType[$this->xmlReader->depth] = $this->xmlReader->nodeType;
			$this->nodesCounter[$this->xmlReader->depth] = 1;
		}
		$this->prevDepth = $this->xmlReader->depth;
		return $read;
	}

	/**
	 * Return current xpath node
	 *
	 * @param boolean $nodesCounter
	 * @return string
	 * @throws Exception
	 */
	public function currentXpath(bool $nodesCounter = false): string
	{
		if (count($this->nodesCounter) !== count($this->nodesParsed) && count($this->nodesCounter) !== count($this->nodesType)) {
			throw new SimpleXmlParserException("Empty reader");
		}
		$result = "";
		foreach ($this->nodesParsed as $depth => $name) {
			switch ($this->nodesType[$depth]) {
				case $this->xmlReader::ELEMENT:
					$result .= "/" . $name;
					if ($nodesCounter) {
						$result .= "[" . $this->nodesCounter[$depth] . "]";
					}
					break;

				case $this->xmlReader::TEXT:
				case $this->xmlReader::CDATA:
					$result .= "/text()";
					break;

				case $this->xmlReader::COMMENT:
					$result .= "/comment()";
					break;

				case $this->xmlReader::ATTRIBUTE:
					$result .= "[@{$name}]";
					break;
			}
		}
		return $result;
	}

	public function readInnerXml(): string
	{
		return $this->xmlReader->readInnerXml();
	}

	/**
	 * Run parser
	 *
	 * @return void
	 * @throws Exception
	 */
	public function parse(): void
	{
		if (empty($this->callback)) {
			throw new SimpleXmlParserException("Empty parser callback.");
		}
		$continue = true;
		while ($continue && $this->read()) {
			if (!isset($this->callback[$this->xmlReader->nodeType])) {
				continue;
			}
			if (isset($this->callback[$this->xmlReader->nodeType][$this->xmlReader->name])) {
				$continue = call_user_func($this->callback[$this->xmlReader->nodeType][$this->xmlReader->name], $this);
			} else {
				$xpath = $this->currentXpath(false); // without node counter
				if (isset($this->callback[$this->xmlReader->nodeType][$xpath])) {
					$continue = call_user_func($this->callback[$this->xmlReader->nodeType][$xpath], $this);
				} else {
					$xpath = $this->currentXpath(true); // with node counter
					if (isset($this->callback[$this->xmlReader->nodeType][$xpath])) {
						$continue = call_user_func($this->callback[$this->xmlReader->nodeType][$xpath], $this);
					}
				}
			}
		}
	}

	/**
	 * Run XPath query on current node
	 *
	 * @param string $path
	 * @param string $version
	 * @param string $encoding
	 * @param string|null $className
	 * @return array(SimpleXMLElement)
	 * @throws DOMException
	 */
	public function expandXpath(string $path, string $version = "1.0", string $encoding = "UTF-8", ?string $className = null): array
	{
		return $this->expandSimpleXml($version, $encoding, $className)->xpath($path);
	}

	/**
	 * Expand current node to string
	 *
	 * @param string $version
	 * @param string $encoding
	 * @param string|null $className
	 * @return bool|string
	 * @throws DOMException
	 */
	public function expandString(string $version = "1.0", string $encoding = "UTF-8", ?string $className = null): bool|string
	{
		return $this->expandSimpleXml($version, $encoding, $className)->asXML();
	}

	/**
	 * Expand current node to SimpleXMLElement
	 *
	 * @param string $version
	 * @param string $encoding
	 * @param string|null $className
	 * @return SimpleXMLElement
	 * @throws DOMException
	 */
	public function expandSimpleXml(string $version = "1.0", string $encoding = "UTF-8", ?string $className = null): SimpleXMLElement
	{
		$element = $this->xmlReader->expand();
		$document = new DomDocument($version, $encoding);
		$document->preserveWhiteSpace = $this->preserveWhiteSpace;
		if ($element instanceof DOMCharacterData) {
			$nodeName = array_splice($this->nodesParsed, -2, 1);
			$nodeName = (isset($nodeName[0]) && $nodeName[0] ? $nodeName[0] : "root");
			$node = $document->createElement($nodeName);
			$node->appendChild($element);
			$element = $node;
		}
		$node = $document->importNode($element, true);
		$document->appendChild($node);
		return simplexml_import_dom($node, $className);
	}

	/**
	 * Expand current node to DomDocument
	 *
	 * @param string $version
	 * @param string $encoding
	 * @return DomDocument
	 * @throws DOMException
	 */
	public function expandDomDocument(string $version = "1.0", string $encoding = "UTF-8"): DOMDocument
	{
		$element = $this->xmlReader->expand();
		$document = new DomDocument($version, $encoding);
		$document->preserveWhiteSpace = $this->preserveWhiteSpace;
		if ($element instanceof DOMCharacterData) {
			$nodeName = array_splice($this->nodesParsed, -2, 1);
			$nodeName = (isset($nodeName[0]) && $nodeName[0] ? $nodeName[0] : "root");
			$node = $document->createElement($nodeName);
			$node->appendChild($element);
			$element = $node;
		}
		$node = $document->importNode($element, true);
		$document->appendChild($node);
		return $document;
	}

}