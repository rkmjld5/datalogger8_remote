SELECT
    id,
    value1,
    value2,
    value3,
    `Date`,
    `Time`,
    `TimeStamp`,
    timezone,
    device_id
FROM logs
ORDER BY id DESC
LIMIT 10;
