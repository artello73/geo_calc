<?php


namespace App;

use App\XmlProcessor\RouteXmlReader;
use Exception;
use JetBrains\PhpStorm\Pure;
use PhpZip\Exception\ZipException;
use PhpZip\ZipFile;

class MapFileProcessor
{
	private ZipFile $zipFile;
	private object $stats;

	/**
	 * @throws ZipException
	 * @throws Exception
	 */
	public function __construct(string $filename)
	{
		$this->zipFile  = new ZipFile();
		$kml = $this->getMapContent($filename);
		$this->stats = $this->processMapContent($kml);
	}

	/**
	 * @throws ZipException
	 */
	private function getMapContent ($fileName) :string {
		try {
			$this->zipFile->openFile($fileName);
			if (isset($this->zipFile['doc.kml'])) {
				$fileAsString = $this->zipFile->getEntryContents('doc.kml');
			}
		} catch (ZipException $e) {
			throw new ZipException ($e->getMessage(), (int)$e->getCode());
		} finally {
			//unlink($fileName);
		}

		return $fileAsString ?? '';
	}

	/**
	 * @throws Exception

	 */
	public function processMapContent(string $data): object
	{
		$reader = new RouteXmlReader();
		$reader->asXML($data);

		try {
			$reader->parse();
		} catch (Exception $e) {
			die("Error: Can't parse XML MAP data.");
		}
		return $reader->getRouteStats();
	}

	#[Pure] public function getMapStats(): object {
		return $this->stats;
	}
//
//	public function getGeoData(): array
//	{
//		return $this->reader->routeArray;
//	}
}