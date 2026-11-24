const WATERMARK_ID = '1.23452384164.123412416'
let intervalId: ReturnType<typeof setInterval> | null = null
let resizeHandler: (() => void) | null = null

const setWatermark = (str: string) => {
  const existing = document.getElementById(WATERMARK_ID)
  if (existing !== null) {
    existing.remove()
  }

  const can = document.createElement('canvas')
  can.width = 200
  can.height = 200

  const canvas = can.getContext('2d')
  if (canvas) {
    canvas.rotate((-15 * Math.PI) / 180)
    canvas.font = '15px Vedana'
    canvas.fillStyle = 'rgba(200, 200, 200, 0.60)'
    canvas.textAlign = 'left'
    canvas.textBaseline = 'middle'
    canvas.fillText(str, can.width / 8, can.height / 2)
  }
  const div = document.createElement('div')
  div.id = WATERMARK_ID
  div.style.pointerEvents = 'none'
  div.style.top = '30px'
  div.style.left = '0px'
  div.style.position = 'fixed'
  div.style.zIndex = '100000'
  div.style.width = `${document.documentElement.clientWidth}px`
  div.style.height = `${document.documentElement.clientHeight}px`
  div.style.background = `url(${can.toDataURL('image/png')}) left top repeat`
  document.body.appendChild(div)
  return WATERMARK_ID
}

const watermark = {
  clear() {
    if (intervalId !== null) {
      clearInterval(intervalId)
      intervalId = null
    }
    if (resizeHandler !== null) {
      window.removeEventListener('resize', resizeHandler)
      resizeHandler = null
    }
    const existing = document.getElementById(WATERMARK_ID)
    if (existing !== null) {
      existing.remove()
    }
  },
  set(str: string) {
    watermark.clear()
    let id = setWatermark(str)
    intervalId = setInterval(() => {
      if (document.getElementById(id) === null) id = setWatermark(str)
    }, 500)
    resizeHandler = () => {
      id = setWatermark(str)
    }
    window.addEventListener('resize', resizeHandler)
    return () => watermark.clear()
  },
}

export default watermark
