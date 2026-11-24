/**
 * HwAdmin 全端共享数据模型与 API 类型契约
 * 自动生成自 hw.project.json 实体建模规范与路由自省契约，严禁手工篡改
 */

export interface ApiResponse<T = any> {
  code: number;
  msg: string;
  data: T;
  time: number;
}

export interface PageResult<T = any> {
  list: T[];
  total: number;
}

export interface UserProfile {
  id: number;
  username: string;
  nickname: string;
  avatar: string;
  email: string;
  mobile: string;
  level: number;
  score: number;
}

export interface UserLoginParams {
  account: string;
  password: string;
}

export interface UserRegisterParams {
  username: string;
  password: string;
  nickname?: string;
  mobile?: string;
}

export interface UserSendResetCodeParams {
  account: string;
}

export interface UserSendResetCodeResult {
  expires_in: number;
  cooldown: number;
  reset_token?: string;
}

export interface UserResetPasswordParams {
  account?: string;
  new_password: string;
  reset_token?: string;
  old_password?: string;
}

export interface UserWechatLoginParams {
  code: string;
  nickname?: string;
  avatar?: string;
}

export interface UserAuthResult {
  token: string;
  expires_in: number;
  openid?: string;
  unionid?: string;
  is_new?: boolean;
  user: UserProfile;
}

export interface UserUploadResult {
  url: string;
  file_name: string;
  mime?: string;
  width?: number;
  height?: number;
}

/** 示例条目 实体接口 */
export interface DemoItemItem {
  id: number; // 主键ID
  title: string; // 条目标题
  content?: string; // 详情内容
  amount: string | number; // 结算金额
  status: number; // 状态(0禁用,1启用)
  create_time: number; // 创建时间
  update_time: number; // 更新时间
}

export type DemoItemListResponse = ApiResponse<PageResult<DemoItemItem>>;
export type DemoItemDetailResponse = ApiResponse<DemoItemItem>;
