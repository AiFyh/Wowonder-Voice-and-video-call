-- ============================================================================
-- 开通「自建 P2P 通话」所需的 Wo_Config 开关
--
-- 表结构：Wo_Config(id, name, value)，name 上没有唯一索引，因此用
-- 「先 UPDATE，不存在才 INSERT」的幂等写法（重复执行结果一致）。
--
-- 各开关含义
--   p2p_chat_video    = 1  启用自建 P2P 通道（本项目的实现，需要信令服务 + coturn）
--   agora_chat_video  = 0  关闭 Agora（否则 sources/video.php 会优先走 Agora 分支）
--   twilio_video_chat = 0  关闭 Twilio
--   video_chat        = 1  允许视频通话（通话页入口开关）
--   audio_chat        = 1  允许语音通话（聊天窗里的语音通话按钮）
-- 可选
--   p2p_stun_fallback     兜底 STUN 地址，例如 stun:turn.example.com:3478
--                         （正常路径由 xhr/p2p_turn.php 动态下发 ICE 列表，这里只是兜底；
--                           不要填 stun.l.google.com，中国大陆不可达）
--
-- ⚠ 如果站点启用了 Memcached 配置缓存（Wo_GetConfig 缓存 TTL 300 秒），
--   直接跑本 SQL 最多 5 分钟才生效；想立刻生效请清缓存/重启 memcached，
--   或直接在后台「视频设置」页面里点开关（会即时清缓存）。
-- ============================================================================

UPDATE Wo_Config SET value='1' WHERE name='p2p_chat_video';
INSERT INTO Wo_Config (name, value) SELECT 'p2p_chat_video','1' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM Wo_Config WHERE name='p2p_chat_video');

UPDATE Wo_Config SET value='0' WHERE name='agora_chat_video';
INSERT INTO Wo_Config (name, value) SELECT 'agora_chat_video','0' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM Wo_Config WHERE name='agora_chat_video');

UPDATE Wo_Config SET value='0' WHERE name='twilio_video_chat';
INSERT INTO Wo_Config (name, value) SELECT 'twilio_video_chat','0' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM Wo_Config WHERE name='twilio_video_chat');

UPDATE Wo_Config SET value='1' WHERE name='video_chat';
INSERT INTO Wo_Config (name, value) SELECT 'video_chat','1' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM Wo_Config WHERE name='video_chat');

UPDATE Wo_Config SET value='1' WHERE name='audio_chat';
INSERT INTO Wo_Config (name, value) SELECT 'audio_chat','1' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM Wo_Config WHERE name='audio_chat');

-- 校验（期望值见上面注释）
SELECT name, value FROM Wo_Config
WHERE name IN ('p2p_chat_video','agora_chat_video','twilio_video_chat','video_chat','audio_chat','p2p_stun_fallback');

-- 通话过程中用到的两张表由主程序自带（xhr/create_new_video_call.php 等写入），
-- 本补丁不含建表 SQL；若报「表不存在」，说明主程序安装不完整：
--   SELECT COUNT(*) FROM Wo_VideoCalles;
--   SELECT COUNT(*) FROM Wo_AudioCalles;
