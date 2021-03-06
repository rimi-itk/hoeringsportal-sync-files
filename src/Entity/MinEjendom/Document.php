<?php

/*
 * This file is part of hoeringsportal-sync-files.
 *
 * (c) 2018–2019 ITK Development
 *
 * This source file is subject to the MIT license.
 */

namespace App\Entity\MinEjendom;

use App\Entity\Archiver;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\SoftDeleteable\Traits\SoftDeleteableEntity;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * @ORM\Entity(repositoryClass="App\Repository\MinEjendom\DocumentRepository")
 * @ORM\Table(
 *     name="minejendom_document",
 *     indexes={
 *         @ORM\Index(name="search_idx", columns={"e_doc_case_sequence_number", "document_identifier", "document_version_identifier"}),
 *         @ORM\Index(name="created_at_idx", columns={"created_at"}),
 *         @ORM\Index(name="updated_at_idx", columns={"updated_at"})
 *     }
 * )
 * @Gedmo\SoftDeleteable()
 */
class Document
{
    use TimestampableEntity;
    use SoftDeleteableEntity;

    /**
     * @ORM\Id()
     * @ORM\GeneratedValue(strategy="UUID")
     * @ORM\Column(type="string")
     */
    private $id;

    /**
     * The eDoc case sequence number.
     *
     * @ORM\Column(type="string", length=255)
     */
    private $eDocCaseSequenceNumber;

    /**
     * The eDoc document identifier.
     *
     * @ORM\Column(type="string", length=255)
     */
    private $documentIdentifier;

    /**
     * The eDoc document version identifier.
     *
     * @ORM\Column(type="string", length=255)
     */
    private $documentVersionIdentifier;

    /**
     * The MinEjendom document guid.
     *
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $documentGuid;

    /**
     * The document filename.
     *
     * @ORM\Column(type="string", length=1024)
     */
    private $filename;

    /**
     * @ORM\Column(type="json")
     */
    private $data = [];

    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\Archiver")
     * @ORM\JoinColumn(nullable=false)
     */
    private $archiver;

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getEDocCaseSequenceNumber(): ?string
    {
        return $this->eDocCaseSequenceNumber;
    }

    public function setEDocCaseSequenceNumber(string $eDocCaseSequenceNumber): self
    {
        $this->eDocCaseSequenceNumber = $eDocCaseSequenceNumber;

        return $this;
    }

    public function getDocumentIdentifier(): ?string
    {
        return $this->documentIdentifier;
    }

    public function setDocumentIdentifier(string $documentIdentifier): self
    {
        $this->documentIdentifier = $documentIdentifier;

        return $this;
    }

    public function getDocumentVersionIdentifier(): ?string
    {
        return $this->documentVersionIdentifier;
    }

    public function setDocumentVersionIdentifier($documentVersionIdentifier): self
    {
        $this->documentVersionIdentifier = $documentVersionIdentifier;

        return $this;
    }

    public function getDocumentGuid(): ?string
    {
        return $this->documentGuid;
    }

    public function setDocumentGuid(string $documentGuid): self
    {
        $this->documentGuid = $documentGuid;

        return $this;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function setData(array $data): self
    {
        $this->data = $data;

        return $this;
    }

    public function addData(string $path, array $value): self
    {
        $accessor = PropertyAccess::createPropertyAccessor();
        $accessor->setValue($this->data, $path, $value);

        return $this;
    }

    public function getArchiver(): ?Archiver
    {
        return $this->archiver;
    }

    public function setArchiver(Archiver $archiver): self
    {
        $this->archiver = $archiver;

        return $this;
    }

    public function getFilename(): ?string
    {
        return $this->filename;
    }

    public function setFilename(string $filename): self
    {
        $this->filename = substr($filename, 0, 1024 - 1);

        return $this;
    }
}
