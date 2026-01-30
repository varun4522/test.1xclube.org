<?php
$balance = 0.00;
$totalDeposit = 0;
$totalWithdraw = 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Wallet</title>

<style>
:root{
    --bg1:#171321;
    --bg2:#0e0c14;
    --card:#1f1b2e;
    --accent:#1ddbd1;
    --soft:rgba(255,255,255,.7);
}
*{box-sizing:border-box;font-family:system-ui,-apple-system,sans-serif}
body{margin:0;background:#9ea1a8}
.app{
    max-width:390px;
    margin:auto;
    min-height:100vh;
    background:linear-gradient(180deg,var(--bg1),var(--bg2));
    color:#fff;
    padding:16px 14px 90px;
}

/* HEADER */
.header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    margin-bottom:16px;
}
.logo{
    display:flex;
    align-items:center;
    gap:8px;
    font-weight:800;
    letter-spacing:1px;
}
.logo-badge{
    width:34px;height:34px;
    border-radius:10px;
    background:var(--accent);
    color:#000;
    display:flex;
    align-items:center;
    justify-content:center;
    font-weight:900;
}
.page-title{font-size:15px;opacity:.8}

/* BALANCE CARD */
.wallet-card{
    background:linear-gradient(160deg,#26213a,#1a1628);
    border-radius:22px;
    padding:22px;
    text-align:center;
    box-shadow:0 20px 45px rgba(0,0,0,.45);
}
.wallet-icon{
    width:46px;height:46px;
    border-radius:14px;
    background:rgba(29,219,209,.15);
    display:flex;
    align-items:center;
    justify-content:center;
    margin:0 auto 8px;
    font-size:22px;
}
.wallet-card h1{
    margin:6px 0 10px;
    font-size:32px;
}
.stats{
    display:flex;
    justify-content:space-between;
    font-size:12px;
    color:var(--soft);
}

/* RINGS */
.rings{
    display:flex;
    justify-content:space-between;
    margin:22px 0 14px;
}
.ring{
    width:142px;
    height:142px;
    border-radius:50%;
    background:
      radial-gradient(circle at center,#1f1b2e 58%,transparent 60%),
      conic-gradient(var(--accent) 0deg,#3a3a4f 0deg);
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
}
.ring strong{font-size:16px}
.ring span{font-size:12px;color:var(--soft)}
.ring small{margin-top:4px;font-size:12px}

/* MENU */
.menu{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:12px;
}
.menu a{text-decoration:none;color:#fff}
.menu-box{
    background:var(--card);
    border-radius:16px;
    padding:14px 6px 12px;
    text-align:center;
    font-size:11.5px;
}
.menu-box img{
    width:28px;
    height:28px;
    margin-bottom:6px;
}

/* GAMES */
.games{
    display:flex;
    gap:12px;
    margin-top:18px;
}
.game{
    flex:1;
    background:linear-gradient(160deg,#1f1b2e,#171321);
    border-radius:16px;
    padding:16px;
    text-align:center;
    font-size:14px;
}
.game small{color:var(--soft)}

/* BOTTOM NAV */
.bottom-nav{
    position:fixed;
    bottom:0;
    left:0;
    right:0;
    max-width:390px;
    margin:auto;
    background:#120f1d;
    display:flex;
    justify-content:space-around;
    padding:10px 0 12px;
    border-top:1px solid rgba(255,255,255,.05);
}
.bottom-nav div{
    font-size:11px;
    color:var(--soft);
}
.bottom-nav .active{
    color:var(--accent);
}
</style>
</head>

<body>

<div class="app">

    <!-- HEADER -->
    <div class="header">
        <div class="logo">
            <div class="logo-badge">1X</div>
            CLUB
        </div>
        <div class="page-title">Wallet</div>
    </div>

    <!-- BALANCE -->
    <div class="wallet-card">
        <div class="wallet-icon">💼</div>
        <h1>₹ <?=number_format($balance,2)?></h1>
        <div class="stats">
            <div><?= $totalDeposit ?><br>Total Deposit</div>
            <div><?= $totalWithdraw ?><br>Total Withdraw</div>
        </div>
    </div>

    <!-- RINGS -->
    <div class="rings">
        <div class="ring">
            <strong>0%</strong>
            <span>Main Wallet</span>
            <small>₹0.00</small>
        </div>
        <div class="ring">
            <strong>0%</strong>
            <span>3rd Party</span>
            <small>₹0.00</small>
        </div>
    </div>

    <!-- MENU -->
    <div class="menu">
        <a href="recharge.php">
            <div class="menu-box">
                <img src="data:image/png;base64,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">
                Deposit
            </div>
        </a>

        <a href="withdraw.php">
            <div class="menu-box">
                <img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAGIAAABiCAMAAACce/Y8AAAAZlBMVEVMaXHT4vYtd9nX5PZYleBXleCjwOmAq+Slwel5qORQj95Hi92HreOCq+JLjd1Ukt5+qOJEid1onOB4puJhmeBbld9toOGRs+Vzo+GMsOTP4PWXt+adu+ejv+hCh9ypw+qux+w9gdtXI/NiAAAACnRSTlMA////sdqSLs1mY17QdgAAAAlwSFlzAAALEwAACxMBAJqcGAAACD1JREFUeNqtmnuX0zYThx9dbMOyZoGWQnp4D+/3/1Y95RxD2ZbTDbAb29L0j5FsOfHuJgElsRVd5qeZ0WVmbMN6espgqDk29UIVd+t1ZpX+cBFyXmH6Ca2voaemr/taS3ug7gH3fRXmEKIJF4SZuKZ2+ygfAI7vbvcYRAaoC8otbFtg27IFWrbttt2iF9hm/B5w3PrbhyCacEFGaKfSbXuMOrYZY+yXnLiFDhpjCVC7tqeZgY9CaJveuYBYa0IzFjW2yHszeEISUstpSYVYQ8BXxq9DVDVeFa3NDwXxsJjaNKqAp66aQ1008UJ5KJjYtiStKmz6tWWu0P+kdMdYfbe7PYgmXgzMYjo/9eBgpPo+7AkqXpDE1Pd9D/Q6008hnW+6bC+qJRf+GUPWBHm1LjLU9FD31H0qq/u6L+qX03Sk4ttYQDy97BXChbOkU/flYFxwjFTUX28nQS2m8UnJzeJZIOABLpsJIrzoAQ8Oh16dcw6mEjcRdGVGmXaphxa6oI2HrBUDPL1E5bRIwa3nV1JwwWmjqeEIFTU3O+VinErv21rcY+JyhJnHElwF1VzxE9LBKCqAFw1Y5pk6coTax4Pr3HVMRSOj6gICeJp20X3Ea0/PqFc/An5MXxjxc96Pqc6PfmT0MC50+uIaw9NnGHoYyMTBp665wK+z5x/iuoIa4dutZSiF4Jd854JR2dq/TiMqruzNzAHDK4shsfHzUjVU1CDEf2yzmAL5ly9VrqmWDaqyR/6mS5UQ8lHq7d40qxioBoYKhtR5oBq0k1YO1TBUDBVDKoREsRoqbZj+CxGMf25RfaceZ6ZMWMHT3iwQb4x/NWZlHCb55Y/Hib/n71WDT1XB1lQtFsMaxlEAmi7NGgJC9F/sy2Vhkb/6ejQCX69WABCAlxaf8jV9zfTpefXhFFV8uMo99TYbwQs7Sg3hVHf1x2nq/mC1f9/X1BNABFwrUQwGcIR0DPU1d/+cOqV23rm+TidTX4MYEKxNXMgswB5qeHP6tH0FNfSZAkaSBfI2jtgD+7nfnrE02qUJJhDxWAueeDhfz1l9y31XzKRuu0b0xTkQvyzpyAIgAlJ8Hjus7z1bBSMIiEgiC3aYNnidAYp1XhIxAmJIcxQ8DMW6MCIYBENxnp8KYsQokmQmsiZUUiZzIu7cDVeSIJYujM0YCGfL6DDFCB6b9OBHiHZR//Ycoot/Nk5cDA80O5sDZULNAzsZDTHGSLTEc2E6iEQSFTvZInZSua6RGG3MI+hWiDyQNkQbk4QiEXwiH2bmIlHh9BhkQ0dHh3472BQw3QzYpY9R/uOsBoBgC/PKJiAior023abbdGw6Nt2mo6NLN61KpDfApttMaigRBrCzQkodGDZsACXVJX42aHGGTvcO6DYzV3EatgUwLxwVcX+ffH545m26x0re3ywMXp2vATvtFf5AeyqBNHK6RZlqKlVu5lIOqAXTOldBhNGTjXg/Pl87uTfdIwbVzdR/YoIQ/GQtekavBr8/4OiYOZudEb8vEVvcvFr7Hs52kr2OdNyjHWaMDO/P3jpURHkNDIRE+id6Fn5fPsWMsnlh/1CaZTOvPwsEBsBGRbH2B3CixaKbYJaTWJmiCTZa3cRsPBPDWouNNiYDcFiqezoBo/0RaUWVeJxIhBVqdpn7/f/GGGPMqyJvjDGm3ZSN7coamKNqAgGGg1llIxZcWuZf3s959Sh2WX1J+GusBxBsy4pNMyR+rXWFoN3BIGwkCT8eCCSPubUTwDD9Bqo8t+jfacNnf8557dlYLNam6Z5NfAYG0pdAyEtF40lDcjyHaqimHeRjMuu+F3kV1NdDp3X2VwcSgoAvTGiqIY1huDtnOt3UVGn8VMMUaLQpch1C2nLVZ35yhgvzv7rYiqawXavOpAmOJKuk+KE/HeK62Y+nBWS22YKqJuCSkurfT0V41xRklnsK7WTIBqdzwBHYvjtRTF9BhRGy3ANCC+5STI9BLAjiAuKCCMLut5tTePhXRBARrIjqOQAtEfPkyrLFrDlGjft0JMAb840pbDtrQ2iJ//oHYrE73lbHqL0evs0qLmSefDFTvySxgft5h58itBD5Yp0nJo2HeUaUt5L1sDfaQDkh5246X7dEtQc0TikYAsEFF1SmgfLfEgNSca5LLUntg7pbLXiMG58TxTR9CiBI8sZk9txK38ylci12Irmw6CfZ6W6JWL44hgsbxTQTyCPe4so/WW/WEvH8PVr1NaI+oBJ5JE4hj+MuETBYepMO3ZYJJHv3RhnXWIAImGIUgiBzHCBFBUQbTwgRB/0zbEQMTZ99fFafxBopZWkAgxEjU1Roqm+bRi0Fc73D6lzyKquWPLn0ky8CRsuRXKqhBv2YVK/MtK2KxU89efIrAmM6fbc/uuwmAAzXd0pVkXzyoNr25OerBfW2LREwyeLZXZuMEaeGCamlpS1u+9+JMlOXGCPeg8H8dZu107zW0Iv6BbZ8yr0tnqfzwJPoRfjBK2HzWXbzo8Nfp2U5Mpm350ZBMgLXt/NTpvHu0oAx+aQVRMTE/CmyJhrSJX3JTdKC8FYpGT7tFk+Kn7ymiCSNP+bBqJ4/3y2flY39s4SdrPjoH4+2FE189FajviaR+Sxh/5G6ec28wR7p2Pl7tgGwn2T/kTo05k2c6Ys5Nb5mslVpAPvpbvUljXqTg4T7McVklObMbKKKEXPIhaXr73kP5Km8mcKEJ3ORSVngr9v7XzWRzfHhOzH3eKyzGlZfmFFG1FA8IYBnZ+9uD2D1tR/z9uxQquGj7I55eamx8la1caRCTNL6x9gf+34U0IiF44O1H4mO2/XK/wAeGOvP325syQAAAABJRU5ErkJggg==">
                Withdraw
            </div>
        </a>

        <a href="rechargehistory.php">
            <div class="menu-box">
                <img src="data:image/png;base64,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">
                Deposit<br>History
            </div>
        </a>

        <a href="withdrawhistory.php">
            <div class="menu-box">
                <img src="data:image/png;base64,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">
                Withdraw<br>History
            </div>
        </a>
    </div>

    <!-- GAMES -->
    <div class="games">
        <div class="game">₹0.00<br><small>Lottery</small></div>
        <div class="game">TB Chess<br><small>Play & Win</small></div>
    </div>

</div>

<div class="bottom-nav">
    <div>Promotion</div>
    <div>Activity</div>
    <div class="active">Wallet</div>
    <div>Account</div>
</div>

</body>
</html>
