<?php

namespace App\Services\Google;

/**
 * Lecture du registre des candidatures dans Google Sheets (portée en lecture seule).
 */
class Sheets
{
    public const SCOPE = 'https://www.googleapis.com/auth/spreadsheets.readonly';

    public function __construct(private GoogleClient $google) {}

    /**
     * @return list<list<string>>
     */
    public function values(string $spreadsheetId, string $range): array
    {
        $response = GoogleClient::check(
            $this->google->api()->get(
                'https://sheets.googleapis.com/v4/spreadsheets/'.rawurlencode($spreadsheetId).'/values/'.rawurlencode($range),
                ['valueRenderOption' => 'FORMATTED_VALUE']
            ),
            'Lecture du registre Google Sheets impossible'
        );

        return $response->json('values') ?? [];
    }
}
