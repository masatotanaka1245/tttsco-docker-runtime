<?php

final class ChatModelRolePayload
{
    public static function build(
        ?string $mainModel,
        ?string $subModel,
        ?string $embeddingModel,
        string $appliedRole = 'main',
        ?string $sqlModel = null
    ): array {
        return [
            'main_model' => $mainModel,
            'sub_model' => $subModel,
            'sql_model' => $sqlModel ?? $subModel,
            'embedding_model' => $embeddingModel,
            'applied_role' => $appliedRole,
        ];
    }
}
