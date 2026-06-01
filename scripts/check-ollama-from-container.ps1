Set-Location $PSScriptRoot\..
docker compose exec app curl http://host.docker.internal:11434/api/tags
