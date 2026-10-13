import { execFileSync } from 'child_process';
import path from 'path';

const LOCAL_MAGENTO_ROOT = path.resolve(__dirname, '../../../../../..');

/**
 * Runs a PHP snippet inside a bootstrapped Magento, with `$om` (the object manager) in scope.
 *
 * Locally the suite runs inside the DDEV web container, on the same filesystem as the store, so a
 * plain `php` is enough. In CI the store is a container driven from outside: `E2E_MAGENTO_EXEC`
 * is the command that gets inside it (`docker exec store`) and `E2E_MAGENTO_ROOT` the install's
 * path in there.
 *
 * The command is passed as an argument list, so nothing in the snippet goes through a shell.
 */
export function runMagentoPhp(body: string): string {
    const magentoRoot = process.env.E2E_MAGENTO_ROOT || LOCAL_MAGENTO_ROOT;
    const script = `
        require '${magentoRoot}/app/bootstrap.php';
        $bootstrap = \\Magento\\Framework\\App\\Bootstrap::create('${magentoRoot}', $_SERVER);
        $om = $bootstrap->getObjectManager();
        $om->get(\\Magento\\Framework\\App\\State::class)->setAreaCode('adminhtml');
        ${body}
    `;

    const exec = (process.env.E2E_MAGENTO_EXEC || '').trim();
    if (exec === '') {
        return execFileSync('php', ['-r', script], { encoding: 'utf8' });
    }

    const [command, ...args] = exec.split(/\s+/);

    return execFileSync(command, [...args, 'php', '-r', script], { encoding: 'utf8' });
}
