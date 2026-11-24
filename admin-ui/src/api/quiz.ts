import request from '/@/utils/request'
import { projectConfig } from '/@/config/project'

const API_BASE = projectConfig.apiBase || '/adminapi'
const QUIZ_BASE = `${API_BASE}/plugin/quiz`

// ==================== 1. 数据仪表盘 ====================
export const getQuizDashboard = () => {
  return request({
    url: `${QUIZ_BASE}/dashboard/index`,
    method: 'get',
  })
}

// ==================== 2. 活动管理 ====================
export const getQuizActivityList = (params?: any) => {
  return request({
    url: `${QUIZ_BASE}/activity/index`,
    method: 'get',
    params,
  })
}

export const addQuizActivity = (data: any) => {
  return request({
    url: `${QUIZ_BASE}/activity/add`,
    method: 'post',
    data,
  })
}

export const editQuizActivity = (id: number | string, data: any) => {
  return request({
    url: `${QUIZ_BASE}/activity/edit?id=${id}`,
    method: 'post',
    data: { id, ...data },
  })
}

export const delQuizActivity = (ids: (number | string)[] | string | number) => {
  return request({
    url: `${QUIZ_BASE}/activity/del`,
    method: 'post',
    data: { ids },
  })
}

// ==================== 3. 题库与分类管理 ====================
export const getQuizQuestionList = (params?: any) => {
  return request({
    url: `${QUIZ_BASE}/question/index`,
    method: 'get',
    params,
  })
}

export const addQuizQuestion = (data: any) => {
  return request({
    url: `${QUIZ_BASE}/question/add`,
    method: 'post',
    data,
  })
}

export const editQuizQuestion = (id: number | string, data: any) => {
  return request({
    url: `${QUIZ_BASE}/question/edit?id=${id}`,
    method: 'post',
    data: { id, ...data },
  })
}

export const delQuizQuestion = (ids: (number | string)[] | string | number) => {
  return request({
    url: `${QUIZ_BASE}/question/del`,
    method: 'post',
    data: { ids },
  })
}

export const getQuizCategoryList = (params?: any) => {
  return request({
    url: `${QUIZ_BASE}/question/categories`,
    method: 'get',
    params,
  })
}

export const saveQuizCategory = (data: any) => {
  return request({
    url: `${QUIZ_BASE}/question/saveCategory`,
    method: 'post',
    data,
  })
}

export const delQuizCategory = (ids: (number | string)[] | string | number) => {
  return request({
    url: `${QUIZ_BASE}/question/delCategory`,
    method: 'post',
    data: { ids },
  })
}

export const importQuizQuestions = (items: any[]) => {
  return request({
    url: `${QUIZ_BASE}/question/import`,
    method: 'post',
    data: { items },
  })
}

export const exportQuizQuestions = (params?: any) => {
  return request({
    url: `${QUIZ_BASE}/question/export`,
    method: 'get',
    params,
  })
}

// ==================== 4. 答题记录与排行榜 ====================
export const getQuizAttemptList = (params?: any) => {
  return request({
    url: `${QUIZ_BASE}/attempt/index`,
    method: 'get',
    params,
  })
}

export const delQuizAttempt = (ids: (number | string)[] | string | number) => {
  return request({
    url: `${QUIZ_BASE}/attempt/del`,
    method: 'post',
    data: { ids },
  })
}

export const getQuizLeaderboard = (params?: any) => {
  return request({
    url: `${QUIZ_BASE}/attempt/leaderboard`,
    method: 'get',
    params,
  })
}

export const exportQuizAttempts = (params?: any) => {
  return request({
    url: `${QUIZ_BASE}/attempt/export`,
    method: 'get',
    params,
  })
}

// ==================== 5. 奖品与订单管理 ====================
export const getQuizPrizeList = (params?: any) => {
  return request({
    url: `${QUIZ_BASE}/prize/index`,
    method: 'get',
    params,
  })
}

export const addQuizPrize = (data: any) => {
  return request({
    url: `${QUIZ_BASE}/prize/add`,
    method: 'post',
    data,
  })
}

export const editQuizPrize = (id: number | string, data: any) => {
  return request({
    url: `${QUIZ_BASE}/prize/edit?id=${id}`,
    method: 'post',
    data: { id, ...data },
  })
}

export const delQuizPrize = (ids: (number | string)[] | string | number) => {
  return request({
    url: `${QUIZ_BASE}/prize/del`,
    method: 'post',
    data: { ids },
  })
}

export const getQuizOrderList = (params?: any) => {
  return request({
    url: `${QUIZ_BASE}/prize/orders`,
    method: 'get',
    params,
  })
}

export const shipQuizOrder = (data: any) => {
  return request({
    url: `${QUIZ_BASE}/prize/ship`,
    method: 'post',
    data,
  })
}

// ==================== 6. 意见反馈管理 ====================
export const getQuizFeedbackList = (params?: any) => {
  return request({
    url: `${QUIZ_BASE}/feedback/index`,
    method: 'get',
    params,
  })
}

export const replyQuizFeedback = (data: any) => {
  return request({
    url: `${QUIZ_BASE}/feedback/reply`,
    method: 'post',
    data,
  })
}

export const delQuizFeedback = (ids: (number | string)[] | string | number) => {
  return request({
    url: `${QUIZ_BASE}/feedback/del`,
    method: 'post',
    data: { ids },
  })
}

// ==================== 7. 附件上传 ====================
export const uploadQuizAttachment = (file: File | FormData) => {
  const data = file instanceof FormData ? file : (() => {
    const fd = new FormData()
    fd.append('file', file)
    return fd
  })()
  return request({
    url: `${API_BASE}/system.attachment/upload`,
    method: 'post',
    data,
  })
}
