<?php

// 本アプリで使う検証メッセージの日本語訳（未定義のものは英語にフォールバック）
return [
    'accepted' => ':attributeを承認してください。',
    'after_or_equal' => ':attributeは:date以降の日付にしてください。',
    'array' => ':attributeは配列で指定してください。',
    'between' => [
        'numeric' => ':attributeは:minから:maxの間で指定してください。',
        'string' => ':attributeは:min〜:max文字で指定してください。',
    ],
    'boolean' => ':attributeは真偽値で指定してください。',
    'date_format' => ':attributeは:format形式（例：2026-10-07）で指定してください。',
    'distinct' => ':attributeに重複した値があります。',
    'email' => ':attributeは正しいメールアドレスを指定してください。',
    'enum' => '選択された:attributeは正しくありません。',
    'extensions' => ':attributeは :values のファイルを選択してください。',
    'file' => ':attributeはファイルを指定してください。',
    'in' => '選択された:attributeは正しくありません。',
    'integer' => ':attributeは整数で指定してください。',
    'max' => [
        'array' => ':attributeは:max件以下にしてください。',
        'file' => ':attributeは:maxKB以下のファイルにしてください。',
        'numeric' => ':attributeは:max以下にしてください。',
        'string' => ':attributeは:max文字以内で入力してください。',
    ],
    'mimetypes' => ':attributeはテキストファイル（.txt / .md）を選択してください。',
    'min' => [
        'array' => ':attributeは:min件以上指定してください。',
        'numeric' => ':attributeは:min以上にしてください。',
        'string' => ':attributeは:min文字以上で入力してください。',
    ],
    'numeric' => ':attributeは数値で指定してください。',
    'regex' => ':attributeの形式が正しくありません。',
    'required' => ':attributeを入力してください。',
    'required_if' => ':otherが:valueの場合、:attributeを入力してください。',
    'string' => ':attributeは文字列で指定してください。',
    'unique' => 'その:attributeはすでに使われています。',
    'url' => ':attributeは正しいURL（http/https）を指定してください。',
    'attributes' => [],
];
