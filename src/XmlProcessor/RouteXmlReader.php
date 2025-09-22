<?php

namespace App\XmlProcessor;

use Exception;
use JetBrains\PhpStorm\ArrayShape;
use RuntimeException;

class RouteXmlReader extends SimpleXMLReader
{
	public int $routeCount;
	public string $fileTag;
	public string $routeTag;
	public array $routeArray;
	public float $routeDistance;

	public const int M = 6371009;
	public const float KM = 6371.009;
	public const float MI = 3958.761;
	public const float NM = 3440.070;
	public const int YD = 6967420;
	public const int FT = 20902260;

	/**
	 * @throws Exception
	 */
	public function __construct()
	{
		parent::__construct();
		$this->routeCount = 0;
		$this->routeDistance = 0;
		$this->fileTag = '';
		$this->routeArray = [];
		$this->registerCallback("Document", array($this, "callbackFileTag"));
		$this->registerCallback("coordinates", array($this, "callbackCoordinates"));
	}

	public function asXML(string $source, ?string $encoding = null, $flags = 0): bool
	{
		//cut off newline and tab characters
		$source = preg_replace('/\r+|\n+|\t+/', '', $source);
		return $this->openAsXml($source, $encoding, $flags);
	}

	public function asXmlFile(string $filename, ?string $encoding = null, int $flags = 0): bool
	{
		return $this->openFile($filename, $encoding, $flags);
	}

	/**
	 * @throws Exception
	 */
	protected function callbackCoordinates($reader): bool
	{
		$routeXml = trim($reader->readInnerXml());
		$this->routeArray[] = $routeXml;
		$this->routeDistance += $this->sectionDistance($routeXml);

		++$this->routeCount;
		return true;
	}

	protected function callbackFileTag($reader): bool
	{
		$xml = $reader->expandSimpleXml();
		$this->fileTag = (string)$xml->name;
		$this->routeTag = (string)$xml->Folder->name;
		return true;
	}

	public function getRouteStats(): object
	{
		return (object)[
			"PlacemarkCount" => $this->routeCount,
			"PlacemarkArrayCount" => count($this->routeArray),
			"RouteDistance" => round($this->routeDistance, 2),
			"FileTag" => $this->fileTag,
			"RouteTag" => $this->routeTag
		];
	}

	/**
	 * @throws RuntimeException
	 */
	private function validateRadius($unit)
	{
		if (defined('self::' . $unit)) {
			return constant('self::' . $unit);
		}

		if (is_numeric($unit)) {
			return $unit;
		}

		throw new RuntimeException('Invalid unit or radius: ' . $unit);
	}

	// Takes two sets of geographic coordinates in decimal degrees and produces distance along the great circle line.
	// Optionally takes a fifth argument with one of the predefined units of measurements, or planet radius in custom units.
	/**
	 * @throws Exception
	 */
	private function distance($lat1, $lon1, $lat2, $lon2, $unit = 'KM'): float
	{
		$r = $this->validateRadius($unit);
		$lat1 = deg2rad($lat1);
		$lon1 = deg2rad($lon1);
		$lat2 = deg2rad($lat2);
		$lon2 = deg2rad($lon2);
		$lonDelta = $lon2 - $lon1;
		$a = ((cos($lat2) * sin($lonDelta)) ** 2) + ((cos($lat1) * sin($lat2) - sin($lat1) * cos($lat2) * cos($lonDelta)) ** 2);
		$b = sin($lat1) * sin($lat2) + cos($lat1) * cos($lat2) * cos($lonDelta);
		$angle = atan2(sqrt($a), $b);
		return $angle * $r;
	}

	private function haversineDistance($latitudeFrom, $longitudeFrom, $latitudeTo, $longitudeTo): float
	{// convert from degrees to radians
		$latFrom = deg2rad($latitudeFrom);
		$lonFrom = deg2rad($longitudeFrom);
		$latTo = deg2rad($latitudeTo);
		$lonTo = deg2rad($longitudeTo);

		$latDelta = $latTo - $latFrom;
		$lonDelta = $lonTo - $lonFrom;

		$angle = 2 * asin(sqrt((sin($latDelta / 2) ** 2) +
				cos($latFrom) * cos($latTo) * (sin($lonDelta / 2) ** 2)));
		return $angle * 6371;
	}

	// Takes two sets of geographic coordinates in decimal degrees and produces bearing (azimuth) from the first set of coordinates to the second set.
	private function bearing($lat1, $lon1, $lat2, $lon2): float
	{
		$lat1 = deg2rad($lat1);
		$lon1 = deg2rad($lon1);
		$lat2 = deg2rad($lat2);
		$lon2 = deg2rad($lon2);
		$lonDelta = $lon2 - $lon1;
		$y = sin($lonDelta) * cos($lat2);
		$x = cos($lat1) * sin($lat2) - sin($lat1) * cos($lat2) * cos($lonDelta);
		$brng = atan2($y, $x);
		$brng *= (180 / M_PI);

		if ($brng < 0) {
			$brng += 360;
		}

		return $brng;
	}

	// Takes one set of geographic coordinates in decimal degrees, azimuth and distance to produce a new set of coordinates, specified distance and bearing away from original.
	// Optionally takes a fifth argument with one of the predefined units of measurements or planet radius in custom units.
	/**
	 * @throws Exception
	 */
	#[ArrayShape(["LAT" => "float", "LON" => "float"])] private function destination($lat1, $lon1, $brng, $dt, $unit = 'KM'): array
	{
		$r = $this->validateRadius($unit);
		$lat1 = deg2rad($lat1);
		$lon1 = deg2rad($lon1);
		$lat3 = asin(sin($lat1) * cos($dt / $r) + cos($lat1) * sin($dt / $r) * cos(deg2rad($brng)));
		$lon3 = $lon1 + atan2(sin(deg2rad($brng)) * sin($dt / $r) * cos($lat1), cos($dt / $r) - sin($lat1) * sin($lat3));
		return array(
			"LAT" => rad2deg($lat3),
			"LON" => rad2deg($lon3)
		);
	}

	/**
	 * @throws Exception
	 */
	public function sectionDistance($coordinateSection): float
	{
		$re = '/^(?<currentLon>.*),(?<currentLat>.*),(?<currentAlt>.*)$/i';
		$sectionDistance = 0;
		$prevLat = 0;
		$prevLon = 0;

		$coordinatesBuffer = explode(' ', $coordinateSection);
		foreach ($coordinatesBuffer as $point) {

			if (empty($point)) {
				continue;
			}
			preg_match($re, $point, $matches);
			$currentLat = (float)$matches['currentLat'];
			$currentLon = (float)$matches['currentLon'];
			if ($prevLat === 0 && $prevLon === 0) {
				$prevLat = $currentLat;
				$prevLon = $currentLon;
			}

			$sectionDistance += $this->haversineDistance($prevLat, $prevLon, $currentLat, $currentLon);
			$prevLat = $currentLat;
			$prevLon = $currentLon;
		}
		return $sectionDistance;
	}
}