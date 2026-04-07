type AttemptRecord = {
  failures: number
  windowStartedAt: number
  blockedUntil?: number
}

const MAX_FAILED_ATTEMPTS = 5
const WINDOW_MS = 15 * 60 * 1000
const BLOCK_DURATION_MS = 15 * 60 * 1000
const MAX_TRACKED_KEYS = 10000

const attempts = new Map<string, AttemptRecord>()

function cleanupExpiredEntries(currentTime: number) {
  for (const [key, record] of attempts.entries()) {
    const blockExpired = !record.blockedUntil || record.blockedUntil <= currentTime
    const windowExpired = currentTime - record.windowStartedAt > WINDOW_MS

    if (blockExpired && windowExpired) {
      attempts.delete(key)
    }
  }

  while (attempts.size >= MAX_TRACKED_KEYS) {
    const oldestKey = attempts.keys().next().value as string | undefined
    if (!oldestKey) {
      break
    }
    attempts.delete(oldestKey)
  }
}

export function getLoginBlockRemainingSeconds(key: string): number {
  const record = attempts.get(key)

  if (!record?.blockedUntil) {
    return 0
  }

  const remainingMs = record.blockedUntil - Date.now()

  if (remainingMs <= 0) {
    attempts.delete(key)
    return 0
  }

  return Math.ceil(remainingMs / 1000)
}

export function registerFailedLoginAttempt(key: string): number {
  const currentTime = Date.now()
  cleanupExpiredEntries(currentTime)

  const record = attempts.get(key)

  if (!record || currentTime - record.windowStartedAt > WINDOW_MS) {
    attempts.set(key, {
      failures: 1,
      windowStartedAt: currentTime,
    })
    return 0
  }

  if (record.blockedUntil && record.blockedUntil > currentTime) {
    return Math.ceil((record.blockedUntil - currentTime) / 1000)
  }

  const failures = record.failures + 1

  if (failures >= MAX_FAILED_ATTEMPTS) {
    attempts.set(key, {
      failures,
      windowStartedAt: record.windowStartedAt,
      blockedUntil: currentTime + BLOCK_DURATION_MS,
    })
    return Math.ceil(BLOCK_DURATION_MS / 1000)
  }

  attempts.set(key, {
    failures,
    windowStartedAt: record.windowStartedAt,
  })

  return 0
}

export function clearLoginAttemptCounter(key: string) {
  attempts.delete(key)
}
