<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

class CvTextExtractor
{
    public function extract(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());

        return match ($extension) {
            'txt', 'md' => $this->extractTextFile($file),
            'docx' => $this->extractDocx($file),
            'doc' => $this->extractDoc($file),
            'pdf' => $this->extractPdf($file),
            default => throw new RuntimeException('Unsupported CV format. Use txt, md, docx, doc, or pdf.'),
        };
    }

    private function extractTextFile(UploadedFile $file): string
    {
        return $this->normalizeWhitespace($file->get());
    }

    private function extractDocx(UploadedFile $file): string
    {
        $zip = new ZipArchive();
        if ($zip->open($file->getRealPath()) !== true) {
            throw new RuntimeException('Unable to read DOCX file.');
        }

        $documentXml = $zip->getFromName('word/document.xml');
        $zip->close();

        if (! is_string($documentXml) || $documentXml === '') {
            throw new RuntimeException('DOCX file did not contain readable document text.');
        }

        $text = strip_tags(str_replace(['</w:p>', '</w:tr>'], ["\n", "\n"], $documentXml));

        return $this->normalizeWhitespace(html_entity_decode($text));
    }

    private function extractPdf(UploadedFile $file): string
    {
        $binary = trim((string) shell_exec('which pdftotext 2>/dev/null'));
        if ($binary === '') {
            throw new RuntimeException('PDF extraction requires pdftotext to be installed on the host.');
        }

        $process = new Process([$binary, '-layout', $file->getRealPath(), '-']);
        $process->setTimeout(30);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Unable to extract text from PDF CV.');
        }

        return $this->normalizeWhitespace($process->getOutput());
    }

    private function extractDoc(UploadedFile $file): string
    {
        $binary = trim((string) shell_exec('which textutil 2>/dev/null'));
        if ($binary === '') {
            throw new RuntimeException('DOC extraction requires textutil on the host.');
        }

        $process = new Process([$binary, '-convert', 'txt', '-stdout', $file->getRealPath()]);
        $process->setTimeout(30);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Unable to extract text from DOC CV.');
        }

        return $this->normalizeWhitespace($process->getOutput());
    }

    private function normalizeWhitespace(string $text): string
    {
        $normalized = preg_replace("/\r\n|\r/", "\n", $text) ?? $text;
        $normalized = preg_replace("/\n{3,}/", "\n\n", $normalized) ?? $normalized;

        return trim($normalized);
    }
}
