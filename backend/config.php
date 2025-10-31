<?php
return [
  'excel_path' => __DIR__ . '/intents.csv',
  'model' => 'gemini-2.5-flash',
  'api_key' => getenv('GEMINI_API_KEY') ?: '',
  'gemini_base' => 'https://generativelanguage.googleapis.com/v1beta/models'
];
