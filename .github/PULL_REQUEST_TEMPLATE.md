## 变更类型 (Change Type)

<!-- 请在适用项中填入 x，例如 [x] -->
- [ ] Bug 修复 (Bug fix)
- [ ] 新功能 (New feature)
- [ ] 性能优化 (Performance improvement)
- [ ] 安全加固 (Security enhancement)
- [ ] 文档更新 (Documentation)
- [ ] 构建与 CI/CD 工程化 (Chore / CI)

## 变更说明与动机 (Summary & Motivation)

<!-- 简述本次 PR 解决了什么问题、改动了哪些核心模块 -->

关联 Issue：Fixes #

## 验证与测试记录 (Testing & Verification)

<!-- 请勾选并说明本地实测结果 -->
- [ ] 后端语法检查：`find server -type f -name "*.php" -not -path "*/vendor/*" -print0 | xargs -0 -n1 -P4 php -l`
- [ ] 后端单元测试：`cd server && composer test`
- [ ] 数据库兼容确认：已确认变更兼容 SQLite / MySQL 8.0 / PostgreSQL 16（未使用单库方言拼接）
- [ ] 前端类型与构建检查：`cd admin-ui && pnpm check`
- [ ] 脚本回归测试：`python3 -m unittest discover -s scripts/tests -v`

## 提交前自检 (Checklist)

- [ ] 代码遵循 [AGENTS.md](AGENTS.md) 与 [CONTRIBUTING.md](CONTRIBUTING.md) 中的工程规范
- [ ] 未包含 `.env`、`*.sqlite`、密钥、Token、内网 IP 或个人隐私信息
- [ ] 如涉及接口、配置或架构变更，已同步更新相关文档或 `docs/CHANGELOG.md`
