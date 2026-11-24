#!/usr/bin/env python3
"""Prepare a usable business starter through the existing HwAdmin tools."""
import argparse
from datetime import date, timedelta
import json
from pathlib import Path
import re
import shutil
import sqlite3
import subprocess
import sys
import time

PROFILES = {
    'website': ('corporate-site', ['admin-pc'], '企业网站'),
    'admin': ('enterprise-admin', ['admin-pc'], '业务管理后台'),
    'miniapp': ('booking-system', ['admin-pc', 'mobile-h5', 'mobile-mp-weixin'], '预约小程序'),
}


def _resolve_layout(root: Path) -> tuple[Path, Path, Path]:
    """Resolve backend dir, frontend ui dir, and server public dir for repo or release layout."""
    if (root / 'think').is_file() and not (root / 'server/think').is_file():
        server_dir = root
        ui_dir = root / 'admin-ui' if (root / 'admin-ui').is_dir() else root / 'sources/admin-ui'
    else:
        server_dir = root / 'server'
        ui_dir = root / 'admin-ui'
    return server_dir, ui_dir, server_dir / 'public'


def _read_db_prefix(root: Path, server_dir: Path) -> str:
    for env_path in (server_dir / '.env', root / '.env'):
        if env_path.is_file():
            for line in env_path.read_text(encoding='utf-8').splitlines():
                m = re.match(r'^\s*DB_PREFIX\s*=\s*([^\s#]+)', line)
                if m:
                    prefix = m.group(1).strip().strip('"').strip("'")
                    if re.fullmatch(r'[A-Za-z0-9_]+', prefix):
                        return prefix
    return 'hw_'


def _resolve_sqlite_db(root: Path) -> Path | None:
    for env_file in (root / 'server/.env', root / '.env'):
        if env_file.is_file():
            for line in env_file.read_text(encoding='utf-8', errors='ignore').splitlines():
                stripped = line.strip()
                if stripped and not stripped.startswith('#') and '=' in stripped:
                    k, v = stripped.split('=', 1)
                    if k.strip() == 'DB_NAME':
                        raw_db = v.strip().strip('"').strip("'")
                        if raw_db and raw_db != ':memory:':
                            db_path = Path(raw_db)
                            if not db_path.is_absolute():
                                base = root / 'server' if (root / 'server/think').is_file() else root
                                db_path = base / db_path
                            if db_path.is_file():
                                return db_path
    for candidate in (
        root / 'server/runtime/hwadmin.sqlite',
        root / 'runtime/hwadmin.sqlite',
        root / 'server/runtime/dev.sqlite',
        root / 'runtime/dev.sqlite',
    ):
        if candidate.is_file():
            return candidate
    return None


def _table_exists(conn: sqlite3.Connection, table_name: str) -> bool:
    row = conn.execute(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1",
        (table_name,),
    ).fetchone()
    return row is not None


def _table_is_empty(conn: sqlite3.Connection, table_name: str) -> bool:
    row = conn.execute(f'SELECT COUNT(*) FROM "{table_name}"').fetchone()
    return (row[0] if row else 0) == 0


def seed_initial_business_data(root: Path, kind: str, app_name: str) -> dict[str, int]:
    """Idempotently seed sample business records into SQLite only when target tables are empty."""
    db_path = _resolve_sqlite_db(root)
    if db_path is None:
        return {}

    server_dir, _, _ = _resolve_layout(root)
    prefix = _read_db_prefix(root, server_dir)
    now = int(time.time())
    inserted: dict[str, int] = {}

    conn = sqlite3.connect(str(db_path))
    try:
        with conn:
            if kind == 'website':
                cat_table = f'{prefix}site_category'
                art_table = f'{prefix}site_article'
                inq_table = f'{prefix}site_inquiry'
                cfg_table = f'{prefix}config'

                if _table_exists(conn, cat_table) and _table_is_empty(conn, cat_table):
                    categories = [
                        ('产品与服务', 'services', 30, 1, now, now),
                        ('客户案例', 'cases', 20, 1, now, now),
                        ('新闻动态', 'news', 10, 1, now, now),
                    ]
                    conn.executemany(
                        f'INSERT INTO "{cat_table}" (name, slug, sort, status, create_time, update_time) VALUES (?, ?, ?, ?, ?, ?)',
                        categories,
                    )
                    inserted[cat_table] = len(categories)

                cat_ids: dict[str, int] = {}
                if _table_exists(conn, cat_table):
                    for cid, slug in conn.execute(f'SELECT id, slug FROM "{cat_table}"').fetchall():
                        cat_ids[str(slug)] = int(cid)

                if _table_exists(conn, art_table) and _table_is_empty(conn, art_table):
                    articles = [
                        (
                            cat_ids.get('services', 0),
                            'product',
                            f'{app_name} 核心解决方案',
                            'core-solution',
                            '面向企业数字化转型的标准化产品与一站式交付服务方案。',
                            f'<p>{app_name} 提供从需求调研、方案设计到落地交付的全流程专业服务，助力企业降本增效。</p>',
                            '',
                            9800.00,
                            128,
                            f'{app_name} 核心解决方案',
                            f'{app_name},产品服务,解决方案',
                            '面向企业数字化转型的标准化产品与一站式交付服务方案。',
                            '',
                            1,
                            now,
                            now,
                        ),
                        (
                            cat_ids.get('cases', 0),
                            'case',
                            f'{app_name} 标杆客户交付案例',
                            'benchmark-case',
                            '某行业领军客户全栈升级实践，交付周期缩短 40%，业务响应效率显著提升。',
                            '<p>本项目围绕核心业务流程重构与管理协同展开，如期完成多端上线与稳定运行验收。</p>',
                            '',
                            0.00,
                            96,
                            f'{app_name} 标杆客户交付案例',
                            f'{app_name},客户案例,成功实践',
                            '某行业领军客户全栈升级实践，交付周期缩短 40%。',
                            '',
                            1,
                            now,
                            now,
                        ),
                        (
                            cat_ids.get('news', 0),
                            'article',
                            f'{app_name} 全新官网与服务体系上线',
                            'service-launch',
                            '欢迎访问官方网站，了解最新产品动态、标杆案例并在线提交合作咨询。',
                            f'<p>{app_name} 官方网站现已正式上线，您可以通过在线询盘表单随时与我们的顾问团队取得联系。</p>',
                            '',
                            0.00,
                            64,
                            f'{app_name} 全新官网上线公告',
                            f'{app_name},新闻动态,公司公告',
                            '欢迎访问官方网站，了解最新产品动态与合作服务。',
                            '',
                            1,
                            now,
                            now,
                        ),
                    ]
                    conn.executemany(
                        f'''INSERT INTO "{art_table}" (
                            category_id, type, title, slug, summary, content, cover_image, price, views,
                            seo_title, seo_keywords, seo_description, canonical_url, status, create_time, update_time
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)''',
                        articles,
                    )
                    inserted[art_table] = len(articles)

                if _table_exists(conn, inq_table) and _table_is_empty(conn, inq_table):
                    target_id = 0
                    if _table_exists(conn, art_table):
                        row = conn.execute(f'SELECT id FROM "{art_table}" ORDER BY id ASC LIMIT 1').fetchone()
                        if row:
                            target_id = int(row[0])
                    conn.execute(
                        f'''INSERT INTO "{inq_table}" (
                            name, mobile, email, company, target_id, content, remark, status, create_time, update_time
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)''',
                        (
                            '张先生',
                            '13800138000',
                            'contact@example.com',
                            '示例合作企业',
                            target_id,
                            f'希望进一步了解 {app_name} 的产品方案与项目合作报价。',
                            '',
                            0,
                            now,
                            now,
                        ),
                    )
                    inserted[inq_table] = 1

                if _table_exists(conn, cfg_table) and inserted:
                    conn.execute(
                        f'''UPDATE "{cfg_table}" SET value = 'cms', update_time = ?
                            WHERE name = 'home_mode' AND value = 'portal' ''',
                        (now,),
                    )

            elif kind == 'admin':
                proj_table = f'{prefix}enterprise_project'
                task_table = f'{prefix}enterprise_task'

                if _table_exists(conn, proj_table) and _table_is_empty(conn, proj_table):
                    projects = [
                        ('PRJ20261101', '核心业务系统建设', 1, 1, 3, 180000.00, 1, now, now),
                        ('PRJ20261102', '客户数据中台升级', 1, 1, 2, 120000.00, 2, now, now),
                    ]
                    conn.executemany(
                        f'''INSERT INTO "{proj_table}" (
                            project_no, title, admin_id, created_by, priority, budget, status, create_time, update_time
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)''',
                        projects,
                    )
                    inserted[proj_table] = len(projects)

                if _table_exists(conn, task_table) and _table_is_empty(conn, task_table) and _table_exists(conn, proj_table):
                    proj_rows = conn.execute(f'SELECT id FROM "{proj_table}" ORDER BY id ASC').fetchall()
                    if proj_rows:
                        p1_id = int(proj_rows[0][0])
                        p2_id = int(proj_rows[1][0]) if len(proj_rows) > 1 else p1_id
                        d7 = (date.today() + timedelta(days=7)).isoformat()
                        d14 = (date.today() + timedelta(days=14)).isoformat()
                        tasks = [
                            (p1_id, '完成需求评审与原型确认', 'admin', d7, 1, 2, now, now),
                            (p1_id, '核心模块接口联调与权限验收', 'admin', d14, 1, 1, now, now),
                            (p2_id, '完成历史数据迁移校验与性能压测', 'admin', d7, 1, 2, now, now),
                        ]
                        conn.executemany(
                            f'''INSERT INTO "{task_table}" (
                                project_id, title, assignee, deadline, created_by, status, create_time, update_time
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)''',
                            tasks,
                        )
                        inserted[task_table] = len(tasks)

            elif kind == 'miniapp':
                svc_table = f'{prefix}booking_service'
                slot_table = f'{prefix}booking_slot'

                if _table_exists(conn, svc_table) and _table_is_empty(conn, svc_table):
                    services = [
                        ('资深顾问一对一咨询', '', 60, 199.00, 1, now, now),
                        ('门店专属体验服务', '', 90, 299.00, 1, now, now),
                    ]
                    conn.executemany(
                        f'''INSERT INTO "{svc_table}" (
                            title, cover, duration, price, status, create_time, update_time
                        ) VALUES (?, ?, ?, ?, ?, ?, ?)''',
                        services,
                    )
                    inserted[svc_table] = len(services)

                if _table_exists(conn, slot_table) and _table_is_empty(conn, slot_table) and _table_exists(conn, svc_table):
                    svc_rows = conn.execute(f'SELECT id FROM "{svc_table}" WHERE status = 1 ORDER BY id ASC').fetchall()
                    if svc_rows:
                        slots = []
                        for offset in (1, 2, 3):
                            slot_date = (date.today() + timedelta(days=offset)).isoformat()
                            for (svc_id,) in svc_rows:
                                slots.append((int(svc_id), slot_date, '10:00', '11:00', 5, 0, 1, now, now))
                                slots.append((int(svc_id), slot_date, '14:00', '15:30', 5, 0, 1, now, now))
                        conn.executemany(
                            f'''INSERT INTO "{slot_table}" (
                                service_id, date, start_time, end_time, capacity, booked_count, status, create_time, update_time
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)''',
                            slots,
                        )
                        inserted[slot_table] = len(slots)
    finally:
        conn.close()

    return inserted


def _sync_website_public_assets(root: Path, project: dict, title: str) -> None:
    _, ui_dir, server_public = _resolve_layout(root)
    brand_name = project.get('app_name', title)
    brand_cfg = project.get('brand', {}) if isinstance(project.get('brand'), dict) else {}
    brand_payload = {
        'name': brand_name,
        'tagline': brand_cfg.get('tagline') or '专注价值 · 持续创造',
        'hero_title': brand_cfg.get('hero_title') or f'{brand_name} · 用专业服务创造长期价值',
        'hero_lead': brand_cfg.get('hero_lead') or '从了解需求到方案落地，我们以清晰的沟通、可靠的执行，为客户提供适合自身发展的产品与服务。',
    }

    ui_site_dir = ui_dir / 'public/site'
    ui_site_dir.mkdir(parents=True, exist_ok=True)
    brand_file = ui_site_dir / 'brand.json'
    brand_file.write_text(json.dumps(brand_payload, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')

    html_src = ui_site_dir / 'index.html'
    targets = [server_public / 'site']
    if (server_public / 'admin').is_dir():
        targets.append(server_public / 'admin/site')

    for target_dir in targets:
        target_dir.mkdir(parents=True, exist_ok=True)
        shutil.copy2(brand_file, target_dir / 'brand.json')
        if html_src.is_file():
            shutil.copy2(html_src, target_dir / 'index.html')


def prepare(root, kind=None, name=None, runner=subprocess.run):
    state_file = root / '.hw-state/start.json'
    state = json.loads(state_file.read_text(encoding='utf-8')) if state_file.is_file() else {}
    kind = kind or state.get('kind', 'admin')
    if state and state.get('kind') != kind:
        raise ValueError('已有项目请直接运行 hw start 继续开发；在现有业务上扩展功能即可，无需重新选模板。')
    template, clients, title = PROFILES[kind]
    project_file = root / 'hw.project.json'
    project = json.loads(project_file.read_text(encoding='utf-8'))
    if not state:
        # Select only the clients this business starter needs. Keep entities and custom settings.
        project['clients'] = clients
    if name:
        project['app_name'] = name
        project.setdefault('brand', {})['name'] = name
    project_file.write_text(json.dumps(project, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')

    backend_dir = root if (root / 'think').is_file() and not (root / 'server/think').is_file() else root / 'server'
    commands = [(['bash', str(root / 'hw'), 'bootstrap'], root)]
    if not state.get('prepared'):
        commands.append((['php', 'think', 'hw:project', 'apply-template', template, '--with-migrate'], backend_dir))
    for command, directory in commands:
        result = runner(command, cwd=directory)
        if result.returncode:
            print('最短恢复办法：请先运行 ./hw doctor 检查环境，修复后重新运行 ./hw start', file=sys.stderr, flush=True)
            return result.returncode

    app_name = project.get('app_name', title)
    if runner is subprocess.run:
        seed_initial_business_data(root, kind, app_name)

    state_file.parent.mkdir(parents=True, exist_ok=True)
    state_file.write_text(json.dumps({'kind': kind, 'template': template, 'prepared': True}, indent=2) + '\n', encoding='utf-8')
    if kind == 'website':
        _sync_website_public_assets(root, project, title)

    print(f'\n{app_name} 已准备好：{title}模板、数据库、示例业务数据、后台菜单与客户端依赖。', flush=True)
    print('再次运行 ./hw start 即可继续开发；已有业务文件与数据库记录不会被覆盖。', flush=True)
    if kind == 'website':
        print('前台预览地址：http://127.0.0.1:15173/admin/site/index.html （或后端根路径 http://127.0.0.1:8000/ ）', flush=True)
        print('后台管理地址：http://127.0.0.1:15173/admin/', flush=True)
    elif kind == 'admin':
        print('后台管理地址：http://127.0.0.1:15173/admin/', flush=True)
    elif kind == 'miniapp':
        print('后台管理地址：http://127.0.0.1:15173/admin/', flush=True)
        print('小程序业务页面：admin-ui/mobile/src/pages/booking/index.vue', flush=True)
        print('小程序编译预览：pnpm --dir admin-ui/mobile dev:mp', flush=True)
        print('微信开发者工具导入目录：admin-ui/mobile/dist/dev/mp-weixin', flush=True)
    print('默认超管账号：admin / Admin12345（上线前请务必修改密码）', flush=True)
    return 0


def main():
    parser = argparse.ArgumentParser(description='选择业务起点并自动准备项目')
    parser.add_argument('kind', nargs='?', choices=PROFILES)
    parser.add_argument('--name', help='你的应用名称')
    parser.add_argument('--prepare-only', action='store_true', help='准备项目后返回，不启动预览服务')
    args = parser.parse_args()
    try:
        return prepare(Path(__file__).resolve().parents[1], args.kind, args.name)
    except (ValueError, OSError, sqlite3.Error, json.JSONDecodeError) as error:
        print(f'项目准备未完成：{error}', file=sys.stderr)
        print('最短恢复办法：请先运行 ./hw doctor 检查环境，修复缺项后重新运行 ./hw start', file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
