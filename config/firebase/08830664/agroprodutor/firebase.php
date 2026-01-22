<?php

return [
    'project_id' => 'cooprolat---agro-produtor',
    'client_email' => 'firebase-adminsdk-fbsvc@cooprolat---agro-produtor.iam.gserviceaccount.com',
    'private_key' => str_replace(
        "\\n",
        "\n",
        $_ENV['COOPROLAT_FIREBASE_PRIVATE_KEY'] ?? ''
    ),
    'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
    'token_uri' => 'https://oauth2.googleapis.com/token',
];