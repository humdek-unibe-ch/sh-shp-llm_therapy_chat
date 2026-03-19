# Therapy Prompt Assets

All therapy-specific LLM prompt text is externalized under `assets/prompts/therapy/`.

## Structure

- One file per prompt.
- Runtime loading is key-based through:
  - `server/service/prompt/TherapyPromptAssetRegistry.php`
  - `server/service/prompt/TherapyPromptAssetLoader.php`

## Rules

- Keep therapy prompt text in assets, not inline PHP strings.
- Register every prompt key in `TherapyPromptAssetRegistry`.
- Load via `TherapyPromptAssetLoader::load($key)`.
- Missing key/file is fail-closed (runtime exception).

## Dependency

This asset layer depends on `sh-shp-llm` runtime hooks/services for execution, but prompt ownership for therapy behavior is fully inside `sh-shp-llm_therapy_chat`.
