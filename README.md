# Building

[![PMMP API](https://img.shields.io/badge/PMMP-API%202-blue)](https://github.com/pmmp/PocketMine-MP)
[![License](https://img.shields.io/badge/License-GPLv3-green)](https://www.gnu.org/licenses/gpl-3.0.html)
[![PHP](https://img.shields.io/badge/PHP-7.x-purple)](https://www.php.net/)

一个基于 **PocketMine-MP API 2** 的 Minecraft 基岩版服务端插件，提供**圈地保存**与**建筑导入**功能。

## 简介

Building 插件允许服务器管理员和玩家通过简单的圈地操作，将指定区域内的建筑结构保存到本地文件，或从文件导入建筑到世界中的任意位置。适用于建筑复制、地图迁移、服务器建设等场景。

## 功能特性

- 🏗️ **圈地保存**：选取区域后将建筑完整保存为文件
- 📦 **建筑导入**：将保存的建筑文件导入到指定位置
- 🔄 **格式兼容**：建筑文件可在不同服务器之间共享使用
- 🎯 **精准还原**：保留建筑原始方块数据与结构布局

## 环境要求

| 依赖项 | 版本要求 |
|--------|----------|
| PocketMine-MP | API 2 |
| PHP | 7.x（与 PMMP API 2 兼容版本） |

## 安装方法

1. 从 [Releases](https://github.com/rpg636zjhi/Building/releases) 页面下载最新版本的 `.phar` 文件
2. 将 `.phar` 文件放入服务器的 `plugins/` 目录
3. 重启服务器
4. 插件将在 `plugins/Building/` 目录下生成配置文件

## 使用方法

> 以下命令与用法为基于插件功能的推断，具体请以实际插件为准。

### 圈地保存建筑

1. 使用指定工具（如木斧）选取建筑区域的两个对角点
2. 输入保存命令，将选中区域的建筑保存到文件

### 导入建筑

1. 将建筑文件放入插件数据目录
2. 站在目标位置，输入导入命令
3. 建筑将自动生成在当前位置

### 命令说明

| 命令 | 说明 | 权限 |
|------|------|------|
| `/build save <名称>` | 保存当前选中区域为建筑文件 | OP |
| `/build list` | 列出所有已保存的建筑文件 | OP |


## 项目结构

```
Building/
├── src/
│   └── rpg636zjhi/
│       └── Building/
│           ├── Building.php          # 插件主类
│           ├── command/              # 命令处理
│           ├── task/                 # 异步任务
│           └── utils/                # 工具类
├── plugin.yml                        # 插件描述文件
├── LICENSE                           # GPL v3 许可证
└── README.md
```

## 许可证

本项目采用 [GNU General Public License v3.0](LICENSE) 许可证。

```
Copyright (C) 2007 Free Software Foundation, Inc. <http://fsf.org/>
Everyone is permitted to copy and distribute verbatim copies
of this license document, but changing it is not allowed.
```

## 贡献

欢迎提交 Issue 和 Pull Request 来帮助改进这个项目。

## 相关链接

- [PocketMine-MP 官方文档](https://pmmp.io/)

---

> 📝 **说明**：由于项目 README 尚未完善，以上内容基于仓库标题与许可证信息整理。如需补充具体功能描述、命令用法或配置说明，欢迎提交 Pull Request。
