<?php

return [
    'background' => env('OFFER_LETTER_BACKGROUND', true),
    'tesseract' => env('OFFER_LETTER_TESSERACT', 'tesseract'),
    'pdftotext' => env('OFFER_LETTER_PDFTOTEXT', 'pdftotext'),
    'pdftoppm' => env('OFFER_LETTER_PDFTOPPM', 'pdftoppm'),
    'minimum_confidence' => 95,
    'maximum_pages' => 3,
];
