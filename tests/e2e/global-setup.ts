import { execSync } from 'node:child_process';

export default function globalSetup() {
    // デモデータで開発用DBを作り直す（production では Seeder 側のガードで失敗する）
    execSync('php artisan migrate:fresh --seed --force', { stdio: 'inherit' });
}
