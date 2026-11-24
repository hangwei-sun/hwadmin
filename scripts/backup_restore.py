#!/usr/bin/env python3
"""Maintenance-window backups. Stop application writes before backup/restore.

SQL and media restore is not a cross-resource atomic transaction.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tarfile
import tempfile
from datetime import datetime

ROOT = Path(__file__).resolve().parent.parent
SERVER = ROOT / 'server' if (ROOT / 'server/app').is_dir() else ROOT
PAYLOADS = {'.env', 'database.sqlite', 'database.sql', 'public_uploads.tar.gz',
            'private_storage.tar.gz', 'install.lock'}


def run(args, **kwargs):
    return subprocess.run(args, check=True, **kwargs)


def digest(path):
    value = hashlib.sha256()
    with path.open('rb') as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b''):
            value.update(chunk)
    return value.hexdigest()


def read_env(path):
    # Match ThinkPHP Env::load, without evaluating shell or PHP input.
    code = '$v=parse_ini_file($argv[1],true,INI_SCANNER_RAW);if($v===false){exit(1);}echo json_encode($v,JSON_THROW_ON_ERROR);'
    values = json.loads(run(['php', '-r', code, str(path)], capture_output=True, text=True).stdout)
    result = {}
    for key, value in values.items():
        if isinstance(value, dict):
            result.update({f'{key}_{k}'.upper(): str(v) for k, v in value.items()})
        else:
            result[key.upper()] = str(value)
    return result


def db_options(env):
    driver = env.get('DB_DRIVER', env.get('DB_TYPE', 'mysql'))
    if driver not in ('sqlite', 'mysql', 'pgsql'):
        raise ValueError('不支持的数据库驱动')
    return driver


def sql_command(env, backup):
    driver = db_options(env)
    tool = ('mysqldump' if backup else 'mysql') if driver == 'mysql' else ('pg_dump' if backup else 'psql')
    if not shutil.which(tool):
        raise ValueError(f'缺少数据库工具 {tool}')
    host = env.get('DB_HOST') or '127.0.0.1'
    port = env.get('DB_PORT') or ('3306' if driver == 'mysql' else '5432')
    user = env.get('DB_USER') or 'hwadmin_app'
    name = env.get('DB_NAME') or 'hwadmin'
    if any(v.startswith('-') for v in (host, port, user, name)):
        raise ValueError('数据库参数不得以 - 开头')
    process_env = os.environ.copy()
    process_env['MYSQL_PWD' if driver == 'mysql' else 'PGPASSWORD'] = env.get('DB_PASS', '')
    if driver == 'mysql':
        args = [tool, '-h', host, '-P', port, '-u', user]
        if backup:
            args += ['--single-transaction', '--quick', '--skip-lock-tables', '--no-tablespaces']
        args += [name]
    else:
        args = [tool, '-h', host, '-p', port, '-U', user, '-d', name]
        args += ['--no-owner', '--no-privileges'] if backup else ['-v', 'ON_ERROR_STOP=1', '--single-transaction']
    return args, process_env


def sqlite_path(env):
    value = Path(env.get('DB_NAME') or 'runtime/hwadmin.sqlite')
    target = value if value.is_absolute() else SERVER / value
    target = target.absolute()
    if not target.resolve().is_relative_to((SERVER / 'runtime').resolve()):
        raise ValueError('SQLite 数据库必须位于当前部署 runtime 目录内；异机恢复请先在隔离目录重建配置与清单')
    return target


def safe_members(archive, allowed=None, prefix=None):
    seen = set()
    members = []
    for member in archive.getmembers():
        name = member.name.removeprefix('./')
        parts = Path(name).parts
        if not name or name == '.':
            continue
        if name in seen or member.issym() or member.islnk() or not (member.isfile() or member.isdir()):
            raise ValueError('归档有重复路径、链接或特殊文件')
        if name.startswith(('/', '\\')) or '\\' in name or '..' in parts or ':' in name:
            raise ValueError('归档包含不安全路径')
        if allowed is not None and (name not in allowed or not member.isfile()):
            raise ValueError('归档包含未声明文件')
        if prefix and parts[0] != prefix:
            raise ValueError('附件归档路径错误')
        seen.add(name)
        member.name = name
        members.append(member)
    return members


def backup(output):
    env_file = SERVER / '.env'
    env = read_env(env_file)
    driver = db_options(env)
    output.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='hwadmin-backup-') as directory:
        work = Path(directory)
        shutil.copyfile(env_file, work / '.env')
        lock = SERVER / 'config/install.lock'
        if lock.is_file():
            shutil.copyfile(lock, work / 'install.lock')
        if driver == 'sqlite':
            source = sqlite_path(env)
            if not source.is_file():
                raise ValueError('SQLite 数据库不存在')
            code = "$p=new PDO('sqlite:'.$argv[1]);$p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$p->exec('VACUUM INTO '.$p->quote($argv[2]));"
            run(['php', '-r', code, str(source), str(work / 'database.sqlite')])
        else:
            args, process_env = sql_command(env, True)
            with (work / 'database.sql').open('wb') as stream:
                run(args, env=process_env, stdout=stream)
        for source, name in [(SERVER / 'public/uploads', 'public_uploads.tar.gz'),
                             (SERVER / 'runtime/storage/private', 'private_storage.tar.gz')]:
            if source.is_dir():
                with tarfile.open(work / name, 'w:gz', dereference=False) as archive:
                    archive.add(source, arcname=source.name)
                with tarfile.open(work / name) as archive:
                    safe_members(archive, prefix=source.name)
        manifest = {p.name: digest(p) for p in work.iterdir()}
        (work / 'manifest.json').write_text(json.dumps(manifest, indent=2), encoding='utf-8')
        name = 'hwadmin_backup_' + datetime.now().strftime('%Y%m%d_%H%M%S_%f') + '.tar.gz'
        target = output / name
        temporary = output / (name + '.partial')
        try:
            with tarfile.open(temporary, 'w:gz') as archive:
                for path in sorted(work.iterdir()):
                    archive.add(path, arcname=path.name, recursive=False)
            temporary.replace(target)
        finally:
            temporary.unlink(missing_ok=True)
        target.with_name(name + '.sha256').write_text(f'{digest(target)}  {name}\n', encoding='ascii')
        print(f'备份完成: {target}')


def restore(source):
    checksum = source.with_name(source.name + '.sha256')
    if not checksum.is_file() or checksum.read_text().split()[0] != digest(source):
        raise ValueError('外部 SHA-256 校验缺失或失败')
    with tempfile.TemporaryDirectory(prefix='hwadmin-restore-') as directory:
        work = Path(directory)
        with tarfile.open(source) as archive:
            archive.extractall(work, members=safe_members(archive, allowed=PAYLOADS | {'manifest.json'}))
        manifest_file = work / 'manifest.json'
        if not manifest_file.is_file():
            raise ValueError('内部清单缺失')
        manifest = json.loads(manifest_file.read_text())
        actual = {p.name for p in work.iterdir()} - {'manifest.json'}
        if set(manifest) != actual or '.env' not in actual:
            raise ValueError('归档内容与清单不一致')
        for name, expected in manifest.items():
            if digest(work / name) != expected:
                raise ValueError('归档内部文件校验失败')
        env = read_env(work / '.env')
        driver = db_options(env)
        database = work / ('database.sqlite' if driver == 'sqlite' else 'database.sql')
        if not database.is_file() or database.stat().st_size == 0:
            raise ValueError('数据库备份缺失或为空')
        media = []
        for name, prefix, parent in [('public_uploads.tar.gz', 'uploads', SERVER / 'public'),
                                     ('private_storage.tar.gz', 'private', SERVER / 'runtime/storage')]:
            if (work / name).is_file():
                with tarfile.open(work / name) as archive:
                    members = safe_members(archive, prefix=prefix)
                    for member in members:
                        dest = parent / member.name
                        if any(p.is_symlink() for p in [dest, *dest.parents]):
                            raise ValueError('恢复目标存在符号链接')
                        if any(p.exists() and not p.is_dir() for p in dest.parents):
                            raise ValueError('恢复目标父路径不是目录')
                        if dest.exists() and (member.isdir() != dest.is_dir()):
                            raise ValueError('恢复目标文件/目录类型冲突')
                    media.append((name, prefix, parent))
        for dest in [SERVER / '.env', SERVER / 'config/install.lock']:
            if any(p.is_symlink() for p in [dest, *dest.parents]):
                raise ValueError('配置恢复目标存在符号链接')
            if dest.exists() and not dest.is_file():
                raise ValueError('配置恢复目标不是文件')
            if any(p.exists() and not p.is_dir() for p in dest.parents):
                raise ValueError('配置恢复目标父路径不是目录')
        if driver == 'sqlite':
            target = sqlite_path(env)
            if target.exists() and not target.is_file():
                raise ValueError('数据库恢复目标不是文件')
            if any(p.exists() and not p.is_dir() for p in target.parents):
                raise ValueError('数据库恢复目标父路径不是目录')
        if driver != 'sqlite':
            args, process_env = sql_command(env, False)
            with database.open('rb') as stream:
                run(args, env=process_env, stdin=stream)
        else:
            code = "$p=new PDO('sqlite:'.$argv[1]);if($p->query('PRAGMA integrity_check')->fetchColumn()!=='ok'){exit(1);}"
            run(['php', '-r', code, str(database)])
            target = sqlite_path(env)
            if target.is_symlink() or any(p.is_symlink() for p in target.parents):
                raise ValueError('数据库目标存在符号链接')
            if any(Path(str(target) + suffix).exists() for suffix in ('-wal', '-shm', '-journal')):
                raise ValueError('SQLite 存在日志文件；先停止写入并完成 checkpoint')
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(database, target)
        for name, prefix, parent in media:
            parent.mkdir(parents=True, exist_ok=True)
            with tarfile.open(work / name) as archive:
                archive.extractall(parent, members=safe_members(archive, prefix=prefix))
        stamp = datetime.now().strftime('%Y%m%d_%H%M%S_%f')
        if (SERVER / '.env').is_file():
            shutil.copyfile(SERVER / '.env', SERVER / ('.env.pre_restore_' + stamp))
        shutil.copyfile(work / '.env', SERVER / '.env')
        if (work / 'install.lock').is_file():
            (SERVER / 'config').mkdir(exist_ok=True)
            shutil.copyfile(work / 'install.lock', SERVER / 'config/install.lock')
        print('恢复完成；请核对文件属主、应用版本、登录及附件。SQL/媒体恢复非原子事务。')


def main():
    os.umask(0o077)
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('command', nargs='?', choices=['check', 'backup', 'restore'], default='check')
    parser.add_argument('path', nargs='?')
    args = parser.parse_args()
    try:
        if args.command == 'backup':
            backup(Path(args.path) if args.path else ROOT / 'backup')
        elif args.command == 'restore':
            if not args.path:
                raise ValueError('请指定备份压缩包')
            restore(Path(args.path))
        else:
            env = read_env(SERVER / '.env')
            if env.get('APP_DEBUG', '').lower() in ('true', '1', 'on'):
                raise ValueError('生产 APP_DEBUG 必须为 false')
            for relative in ['runtime', 'public/uploads']:
                if not os.access(SERVER / relative, os.W_OK):
                    raise ValueError(f'目录不可写: {relative}')
            if shutil.disk_usage(SERVER).free < 500 * 1024 * 1024:
                raise ValueError('剩余磁盘空间不足 500MB')
            print(f'环境检查通过，数据库: {db_options(env)}')
    except (OSError, ValueError, subprocess.CalledProcessError, tarfile.TarError, KeyError, IndexError) as error:
        print('操作失败：数据库命令执行失败，请检查配置与工具。' if isinstance(error, subprocess.CalledProcessError) else f'操作失败: {error}', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
