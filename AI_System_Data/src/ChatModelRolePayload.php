<?php

final class ChatModelRolePayload
{
    public static function build(
        ?string $mainModel,
        ?string $subModel,
        ?string $embeddingModel,
        string $appliedRole = 'main'
    ): array {
        return [
            'main_model' => $mainModel,
            'sub_model' => $subModel,
            'embedding_model' => $embeddingModel,
            'applied_role' => $appliedRole,
        ];
    }
}
