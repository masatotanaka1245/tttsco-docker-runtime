<?php

require_once __DIR__ . '/ModelRoleResolver.php';

final class ChatModelRolePayload
{
    public static function build(
        ?string $mainModel,
        ?string $subModel,
        ?string $embeddingModel,
        string $appliedRole = 'main',
        ?string $sqlModel = null,
        ?string $visionModel = null
    ): array {
        $resolvedVisionModel = $visionModel;
        if ($resolvedVisionModel === null || $resolvedVisionModel === '') {
            $resolvedVisionModel = trim((string)($_SESSION['vision_model'] ?? ''));
        }
        if ($resolvedVisionModel === '') {
            $resolvedVisionModel = $mainModel ?: ModelRoleResolver::DEFAULT_VISION_MODEL;
        }

        $payload = [
            'main_model' => $mainModel,
            'sub_model' => $subModel,
            'sql_model' => $sqlModel ?? $subModel,
            'embedding_model' => $embeddingModel,
            'vision_model' => $resolvedVisionModel,
            'applied_role' => $appliedRole,
        ];

        return $payload;
    }
}
