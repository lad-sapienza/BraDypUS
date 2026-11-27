<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace File;

/**
 * HTTP headers for sending a stored file to a browser, safely.
 *
 * The files are uploaded by users and now come from the application's own origin, so a file
 * must never be able to run as a page of the application: anything that is not a plain image,
 * PDF, text, audio or video is sent as a download, nothing is ever sniffed into another type,
 * and a restrictive Content-Security-Policy sandboxes whatever a browser does render (an SVG
 * opened on its own, for instance, cannot run scripts).
 */
final class Delivery
{
    /** Extension → content type, for the kinds shown in the browser. */
    private const INLINE = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'avif' => 'image/avif', 'bmp' => 'image/bmp', 'ico' => 'image/x-icon',
        'tif' => 'image/tiff', 'tiff' => 'image/tiff', 'svg' => 'image/svg+xml',
        'pdf' => 'application/pdf', 'txt' => 'text/plain; charset=utf-8',
        'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'm4a' => 'audio/mp4',
        'mp4' => 'video/mp4', 'webm' => 'video/webm',
    ];

    /** Known kinds that are only ever downloaded. */
    private const DOWNLOAD = [
        'csv' => 'text/csv; charset=utf-8', 'json' => 'application/json', 'zip' => 'application/zip',
        'doc' => 'application/msword', 'xls' => 'application/vnd.ms-excel', 'ppt' => 'application/vnd.ms-powerpoint',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt' => 'application/vnd.oasis.opendocument.text', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
    ];

    /** Is a file of this extension shown by the browser (true) or downloaded (false)? */
    public static function isInline(string $ext): bool
    {
        return isset(self::INLINE[strtolower($ext)]);
    }

    public static function contentType(string $ext): string
    {
        $ext = strtolower($ext);
        return self::INLINE[$ext] ?? self::DOWNLOAD[$ext] ?? 'application/octet-stream';
    }

    /**
     * `Content-Disposition` value for the download name `$filename.$ext`. The name is
     * cleaned (no control characters, quotes, slashes) and given both as an ASCII fallback and
     * as RFC 5987 UTF-8.
     */
    public static function disposition(string $filename, string $ext): string
    {
        $name  = trim((string) preg_replace('/[\x00-\x1f\x7f"\\\\\/]+/u', '_', $filename));
        $name  = ($name === '' ? 'file' : $name) . ($ext === '' ? '' : '.' . strtolower($ext));
        $ascii = (string) preg_replace('/[^\x20-\x7e]/u', '_', $name);
        $kind  = self::isInline($ext) ? 'inline' : 'attachment';

        return $kind . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name);
    }

    /**
     * The response headers for a file.
     *
     * @return array<string, string>
     */
    public static function headers(string $filename, string $ext): array
    {
        return [
            'Content-Type'            => self::contentType($ext),
            'Content-Disposition'     => self::disposition($filename, $ext),
            'X-Content-Type-Options'  => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            // Revalidated on every use, so that a change of permissions takes effect at once
            'Cache-Control'           => 'private, no-cache',
            'Vary'                    => 'Authorization, Cookie',
        ];
    }
}
