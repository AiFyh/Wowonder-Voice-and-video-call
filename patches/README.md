# patches —— 对主程序/主题既有文件的增量补丁

本目录只放 **uniified diff 补丁**，**不包含** WowWonder 主程序与 Sean 主题的原始文件。
补丁是相对「基线版本」制作的：基线 md5 见 `known-baselines.tsv`，与你的文件不一致时请按
`docs/INSTALL.md` 的「手工合入」一节处理（每处都给了锚点文本）。

## 用法

```bash
# 先干跑，看哪些能打（不会改任何文件）
bash tools/apply-patches.sh /path/to/site

# 确认后真正应用
bash tools/apply-patches.sh /path/to/site --apply
```

## 补丁清单

| 补丁 | 目标文件 | 基线 md5 | 作用 |
|---|---|---|---|
| `0001-admin-panel_pages_video-settings_content.phtml.patch` | `admin-panel/pages/video-settings/content.phtml` | `d69dc144d78ab3a6d65ef67116670518` | 后台「视频设置」增加「自建 P2P 通道」开关卡片 + 互斥逻辑（启用 P2P 时自动关掉 Agora/Twilio） |
| `0002-xhr_create_new_video_call.php.patch` | `xhr/create_new_video_call.php` | `0ebd63a3a99fc2e2d4c3cf15f0f4d0d4` | 发起视频通话时走 P2P 分支：生成房间号、写 `Wo_VideoCalles`、返回 `/video-call/<id>` |
| `0003-xhr_create_new_audio_call.php.patch` | `xhr/create_new_audio_call.php` | `bfd07abc6715eb160d08fc1285611d3d` | 同上（语音） |
| `0004-themes_Sean_layout_video_content.phtml.patch` | `themes/Sean/layout/video/content.phtml` | `86e7efa20220dd19c751774a52a7b1d5` | 通话页增加 P2P 分支（本地/远端视频容器 + 通话时长元素） |
| `0005-themes_Sean_layout_modals_talking.phtml.patch` | `themes/Sean/layout/modals/talking.phtml` | `67cdcd817738ed443225cb9294fd9d4f` | 语音通话弹窗接入 P2P（信令、bye 通知、计时、挂断写聊天记录） |
| `0006-themes_Sean_layout_messages_messages-text-list.phtml.patch` | `themes/Sean/layout/messages/messages-text-list.phtml` | `bf0366c6d3b57f86deca8a31c3467920` | 消息页把 `type_two=call_log` 的消息渲染成居中的通话记录行 |
| `0007-themes_Sean_layout_chat_chat-list.phtml.patch` | `themes/Sean/layout/chat/chat-list.phtml` | `5fea3bbef32553bea443ccb413dfe87f` | 聊天小窗同上 |
| `0008-themes_Sean_layout_chat_chat-tab.phtml.patch` | `themes/Sean/layout/chat/chat-tab.phtml` | `c1cd7b11d38d1c9874fe5a4dac222da3` | 聊天窗头部在有 P2P 通道时也显示语音/视频按钮 |
| `0009-themes_Sean_layout_messages_content.phtml.patch` | `themes/Sean/layout/messages/content.phtml` | `9f03c4ea5b4b7ce79e9f0741e481fa72` | 消息页顶部的语音/视频按钮条件加上 P2P |
| `0010-themes_Sean_javascript_script.js.patch` | `themes/Sean/javascript/script.js` | `bc7b0219ca6add28e6ec51f7026aa929` | 发起通话的 AJAX 显式带 `hash_id`（会话校验），2 处 |

> `0008`、`0010` 的基线取自主题的姊妹版本（`Seanq`）。这两个文件在原站上另有与本功能**无关**的其他改动，
> 因此补丁里只包含本功能需要的 hunk，不会带进无关内容。

## 补丁是怎么验证的（可复现）

1. 把基线文件放进一个空目录，`patch -p1 < 每个补丁` → 全部干净应用、无 `.rej`；
2. 应用结果与「本项目的目标版本」逐字节一致（md5 相同）；
3. 其中 7 个文件的应用结果与**生产站点**上的文件 **md5 完全相同**（逐字节一致）；
   `talking.phtml` 唯一差异是把写死的源站公网 IP 换成了配置项（开源版不暴露源站 IP），
   `chat-tab.phtml`、`script.js` 的差异只有上文提到的「与本功能无关的其他改动」。
