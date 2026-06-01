Set-Location $PSScriptRoot\..
docker compose up -d --build
docker compose ps
Write-Host ""
Write-Host "アプリ:      http://localhost:8080"
Write-Host "phpMyAdmin:  http://localhost:8081"
Write-Host ""
Write-Host "Ollama確認: curl http://localhost:11434/api/tags"
