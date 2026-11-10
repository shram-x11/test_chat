local messages, names, online, sendLimit = KEYS[1], KEYS[2], KEYS[3], KEYS[4]
local action, participant, value = ARGV[1], ARGV[2], ARGV[3]
local time = redis.call('TIME')
local now = tonumber(time[1]) * 1000 + math.floor(tonumber(time[2]) / 1000)
local historyStart = now - 1800000
local historyId = string.format('%.0f-0', historyStart)

local function remove(id, announce)
    local name = redis.call('HGET', names, id)
    redis.call('ZREM', online, id)
    if name then
        redis.call('HDEL', names, id)
        if announce then
            redis.call('XADD', messages, '*', 'type', 'leave', 'name', name)
        end
    end
end

redis.call('XTRIM', messages, 'MINID', historyId)
local expired = redis.call('ZRANGEBYSCORE', online, '-inf', now, 'WITHSCORES')
for index = 1, #expired, 2 do
    remove(expired[index], tonumber(expired[index + 1]) >= historyStart)
end

if action == 'leave' then
    remove(participant, true)
    return {200}
end

local name = redis.call('HGET', names, participant)
if action == 'join' and not name then
    name = value
    redis.call('HSET', names, participant, name)
    redis.call('XADD', messages, '*', 'type', 'join', 'name', name)
end

if not name then
    return {410}
end

if action == 'send' then
    if not redis.call('SET', sendLimit, '1', 'PX', 600, 'NX') then
        return {429}
    end
    redis.call('XADD', messages, '*', 'type', 'message', 'name', name, 'text', value)
end

redis.call('ZADD', online, now + 30000, participant)
return {200, historyId}
