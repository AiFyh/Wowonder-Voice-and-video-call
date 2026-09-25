# 贡献指南

## 提交前请先确认

* 本仓库**不接受** WowWonder 主程序、Sean/Seanq 主题或其它第三方产品的源码文件（哪怕是被修改过的完整文件）。
  对既有文件的改动请以 **补丁（unified diff）** 形式提交；
* 提交内容里**不得包含任何真实密钥、密码、Token、私钥、真实源站 IP 或域名凭据**；
  占位符统一写成 `<内网IP>` / `<公网IP>` / `<你的域名>` / `<用 openssl rand -hex 32 生成>`。

## 修改方式

1. 原创文件（`src/`、`css/`、`server/` 等）直接改；
2. 补丁（`patches/`）需要重新生成，并同步 `patches/known-baselines.tsv`：

   ```bash
   # 在干净的基线副本上改好后：
   diff -u --label a/<相对路径> --label b/<相对路径> <基线文件> <改后文件> > patches/00NN-<名字>.patch
   md5sum <基线文件>        # 写进 patches/known-baselines.tsv
   ```

3. 新增/重命名补丁后，确认 `tools/apply-patches.sh` 仍能按顺序打好（先干跑）。

## 自测清单

* `php -l` 通过（所有 `.php` / `.phtml`）；
* 模板里的内联 JS 用 `node --check` 过一遍（可先渲染出 HTML 再抽出 `<script>` 内容）；
* CSS 追加后 `{` 与 `}` 数量仍然配平；
* 补丁在**基线文件**上能干净应用（无 `.rej`），且应用结果与预期一致；
* `bash -n` 通过（所有 shell 脚本）；
* 若是行为改动，在 `CHANGELOG.md` 的 `Unreleased` 段落里写一行。

## 代码风格

* 保持与主程序一致：PHP 用 `T_` 表常量、`Wo_Secure()`、`Wo_RegisterMessage()` 等既有函数，不引入新依赖；
* 注释写「为什么」而不是「做了什么」，踩过的坑请写进 `docs/TROUBLESHOOTING.md`；
* 前端避免引入构建步骤，直接用文件 + 追加样式的方式接入。

## 报告问题

请附上：系统与版本（Node/coturn/浏览器）、`journalctl -u peerjs` 的相关日志、
浏览器控制台里的 `[P2P]`/`[P2P-Audio]` 日志、以及 `docs/TROUBLESHOOTING.md` 里已排查过的步骤结果。
**不要**在 issue 里贴真实的 TURN 密钥或服务器密码。
