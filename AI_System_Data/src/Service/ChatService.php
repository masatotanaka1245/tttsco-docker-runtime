<?php
namespace App\Service;

class ChatService {
    private string $host = "http://tsc23ews009:11434";

    public function getEmbedding(string $text): ?string {
        $ch = curl_init("{$this->host}/api/embeddings");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["model" => "mxbai-embed-large", "prompt" => $text]));
        $res = curl_exec($ch);
        curl_close($ch);
        if (!$res) return null;
        $json = json_decode($res, true);
        return isset($json['embedding']) ? json_encode($json['embedding']) : null;
    }

    public function chat(string $model, array $messages): string {
        $ch = curl_init("{$this->host}/api/chat");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            "model" => $model,
            "messages" => $messages,
            "stream" => false
        ]));
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        $res = curl_exec($ch);
        curl_close($ch);
        $json = json_decode($res, true);
        return $json['message']['content'] ?? "AI応答エラー";
    }
}