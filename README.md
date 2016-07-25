# taobao-crawler-and-translate-program
淘宝抓取和翻译程序


对淘宝的商品搜索、商品详情、商品评论、商家信息的抓取程序，里面有大量的匹配正则DSG_rulesList.xml和DSG_rateRule.xml两个文件。

相关文章：

淘宝商品评论及卖家相关数据的抓取程序 http://www.04007.cn/article/177.html  

调用淘宝API接口获取产品详情数据-淘宝API封装 http://www.04007.cn/article/176.html

淘宝详情页的php抓取程序 http://www.04007.cn/article/175.html

淘宝列表页php抓取程序  http://www.04007.cn/article/174.html

另外对淘宝产品抓取下来之后进行翻译，还涉及到商品搜索时的可筛选商品属性列表，将产品的PID,VID值翻译后存储在缓存中供下次调用（这部分可以不用每次翻译）。

翻译的程序有多种方式：Google翻译，BING翻译（bing翻译需要使用账号，免费账号有流量限制，所以可注册多个账号放在程序里面随机调用）。还有俄罗斯的一个dropshop翻译接口，不过不知道现在还。
好不好使了。

抓取淘宝时有两种方式：一种是使用淘宝的API接口（TbApiData.php），一种是直接抓取淘宝的网页TbData.php，开始抓取一切都正常，但大约几个月后吧，发现淘宝
的抓取不行了，原因是在抓取的时候会先跳入一个验证码页面（现在也忘记了，不排除能通过对curl时的参数进行调整可解决）
