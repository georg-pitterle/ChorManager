<?php

declare(strict_types=1);

namespace App\Services;

use App\Util\Timezone;
use DOMDocument;
use DOMElement;

/**
 * Baut die XML-Antworten des Noten-Ordners.
 *
 * Über DOMDocument und nicht per Zeichenkette: Die Namen darin sind Liedtitel
 * und Dateinamen aus Uploads. Ein "Tuba mirum & Rex tremendae" hätte eine
 * zusammengesetzte Antwort zerlegt, und der Klient hätte statt einer
 * Fehlermeldung einen halb gelesenen Ordner gezeigt.
 */
class WebdavXmlBuilder
{
    private const NS = 'DAV:';

    /**
     * Das Datumsformat von HTTP, ausgeschrieben statt über DATE_RFC7231: Die
     * Konstante ist seit PHP 8.5 verpönt, weil sie die Zeitzone des Datums
     * ignoriert und stillschweigend GMT anhängt. Umgerechnet wird deshalb hier,
     * und die Zone steht danach zu Recht als feste Zeichenkette im Format.
     */
    private const HTTP_DATE_FORMAT = 'D, d M Y H:i:s \G\M\T';

    /**
     * Was der Ordner an Eigenschaften kennt. Alles andere wird im
     * 207-Multistatus als 404 gemeldet - das ist die vorgesehene Antwort und
     * kein Fehler, Klienten fragen reihenweise Eigenschaften ab, die es nur auf
     * anderen Servern gibt.
     *
     * @var list<string>
     */
    private const KNOWN_PROPERTIES = [
        'resourcetype',
        'displayname',
        'getcontentlength',
        'getcontenttype',
        'getlastmodified',
        'creationdate',
        'getetag',
    ];

    /**
     * @param list<array{href: string, node: WebdavNode}> $resources
     * @param list<string>|null $requestedProperties null bedeutet allprop
     */
    public function multiStatus(array $resources, ?array $requestedProperties, bool $namesOnly = false): string
    {
        $document = $this->document();
        $root = $document->createElementNS(self::NS, 'D:multistatus');
        $document->appendChild($root);

        foreach ($resources as $resource) {
            $root->appendChild($this->responseFor(
                $document,
                $resource['href'],
                $resource['node'],
                $requestedProperties,
                $namesOnly
            ));
        }

        return (string) $document->saveXML();
    }

    /**
     * Die Vorbedingung, an der eine Anfrage gescheitert ist - etwa
     * `propfind-finite-depth` für ein PROPFIND mit `Depth: infinity`.
     */
    public function error(string $condition): string
    {
        $document = $this->document();
        $root = $document->createElementNS(self::NS, 'D:error');
        $root->appendChild($document->createElementNS(self::NS, 'D:' . $condition));
        $document->appendChild($root);

        return (string) $document->saveXML();
    }

    private function document(): DOMDocument
    {
        $document = new DOMDocument('1.0', 'utf-8');
        $document->formatOutput = false;

        return $document;
    }

    /**
     * @param list<string>|null $requestedProperties
     */
    private function responseFor(
        DOMDocument $document,
        string $href,
        WebdavNode $node,
        ?array $requestedProperties,
        bool $namesOnly
    ): DOMElement {
        $response = $document->createElementNS(self::NS, 'D:response');
        $response->appendChild($this->textElement($document, 'D:href', $href));

        $wanted = $requestedProperties ?? self::KNOWN_PROPERTIES;

        $found = [];
        $missing = [];

        foreach ($wanted as $property) {
            $element = $namesOnly
                ? ($this->isKnown($property) ? $document->createElementNS(self::NS, 'D:' . $property) : null)
                : $this->propertyElement($document, $node, $property);

            if ($element === null) {
                $missing[] = $property;
                continue;
            }

            $found[] = $element;
        }

        if ($found !== []) {
            $response->appendChild($this->propstat($document, $found, 'HTTP/1.1 200 OK'));
        }

        if ($missing !== []) {
            $elements = [];
            foreach ($missing as $property) {
                $elements[] = $this->unknownElement($document, $property);
            }

            $response->appendChild($this->propstat($document, $elements, 'HTTP/1.1 404 Not Found'));
        }

        return $response;
    }

    /**
     * @param list<DOMElement> $properties
     */
    private function propstat(DOMDocument $document, array $properties, string $status): DOMElement
    {
        $propstat = $document->createElementNS(self::NS, 'D:propstat');
        $prop = $document->createElementNS(self::NS, 'D:prop');

        foreach ($properties as $property) {
            $prop->appendChild($property);
        }

        $propstat->appendChild($prop);
        $propstat->appendChild($this->textElement($document, 'D:status', $status));

        return $propstat;
    }

    private function isKnown(string $property): bool
    {
        return in_array($property, self::KNOWN_PROPERTIES, true);
    }

    /**
     * Ein unbekannter Eigenschaftsname wird als leeres Element im
     * 404-Abschnitt wiederholt. Er stammt aus der Anfrage und ist deshalb
     * entschärft - DOMDocument nimmt nur gültige Namen an, alles andere
     * landet als Element `D:unknown`.
     */
    private function unknownElement(DOMDocument $document, string $property): DOMElement
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*\z/', $property) !== 1) {
            return $document->createElementNS(self::NS, 'D:unknown');
        }

        return $document->createElementNS(self::NS, 'D:' . $property);
    }

    private function propertyElement(DOMDocument $document, WebdavNode $node, string $property): ?DOMElement
    {
        return match ($property) {
            'resourcetype' => $this->resourceType($document, $node),
            'displayname' => $this->textElement($document, 'D:displayname', $node->name),
            'getcontentlength' => $node->isCollection
                ? null
                : $this->textElement($document, 'D:getcontentlength', (string) $node->size),
            'getcontenttype' => $node->isCollection || $node->mimeType === ''
                ? null
                : $this->textElement($document, 'D:getcontenttype', $node->mimeType),
            'getlastmodified' => $this->dateElement($document, 'D:getlastmodified', $node, true),
            'creationdate' => $this->dateElement($document, 'D:creationdate', $node, false),
            'getetag' => $node->isCollection ? null : $this->textElement($document, 'D:getetag', $this->etag($node)),
            default => null,
        };
    }

    private function resourceType(DOMDocument $document, WebdavNode $node): DOMElement
    {
        $element = $document->createElementNS(self::NS, 'D:resourcetype');

        if ($node->isCollection) {
            $element->appendChild($document->createElementNS(self::NS, 'D:collection'));
        }

        return $element;
    }

    private function dateElement(DOMDocument $document, string $name, WebdavNode $node, bool $asHttpDate): ?DOMElement
    {
        if ($node->lastModified === null) {
            return null;
        }

        try {
            $date = new \DateTimeImmutable($node->lastModified, new \DateTimeZone(Timezone::resolveAppTimezone()));
        } catch (\Exception) {
            return null;
        }

        $value = $asHttpDate
            ? $date->setTimezone(new \DateTimeZone('UTC'))->format(self::HTTP_DATE_FORMAT)
            : $date->format(DATE_ATOM);

        return $this->textElement($document, $name, $value);
    }

    /**
     * Kennung und Größe reichen: Der Inhalt eines Anhangs wird nie überschrieben,
     * ein Upload legt eine neue Zeile an.
     */
    private function etag(WebdavNode $node): string
    {
        return '"' . $node->attachmentId . '-' . $node->size . '"';
    }

    private function textElement(DOMDocument $document, string $name, string $value): DOMElement
    {
        $element = $document->createElementNS(self::NS, $name);
        $element->appendChild($document->createTextNode($value));

        return $element;
    }
}
